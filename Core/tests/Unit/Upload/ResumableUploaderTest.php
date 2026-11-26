<?php
/**
 * Copyright 2016 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Cloud\Core\Tests\Unit\Upload;

use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Core\RequestWrapper;
use Google\Cloud\Core\Upload\ResumableUploader;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Argument;

/**
 * @group core
 * @group upload
 */
class ResumableUploaderTest extends TestCase
{
    use ProphecyTrait;

    private $requestWrapper;
    private $stream;
    private $successBody;

    public function setUp(): void
    {
        $this->requestWrapper = $this->prophesize(RequestWrapper::class);
        $this->stream = Utils::streamFor('abcd');
        $this->successBody = '{"canI":"kickIt"}';
    }

    public function testUploadsData()
    {
        $response = new Response(200, ['Location' => 'theResumeUri'], $this->successBody);

        $this->requestWrapper->send(
            Argument::type(RequestInterface::class),
            Argument::type('array')
        )->willReturn($response);

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'http://www.example.com'
        );

        $this->assertEquals(json_decode($this->successBody, true), $uploader->upload());
    }

    public function testUploadsDataWithCallback()
    {
        $response = new Response(200, ['Location' => 'theResumeUri'], $this->successBody);

        $called = false;
        $callback = function () use (&$called) {
            $called = true;
        };

        $this->requestWrapper->send(
            Argument::type(RequestInterface::class),
            Argument::type('array')
        )->willReturn($response);

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'http://www.example.com',
            ['uploadProgressCallback' => $callback]
        );

        $this->assertEquals(json_decode($this->successBody, true), $uploader->upload());
        $this->assertTrue($called);
    }

    public function testUploadsDataWithInvalidCallback()
    {
        $this->expectException(InvalidArgumentException::class);

        $callback = 'foo';

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'http://www.example.com',
            ['uploadProgressCallback' => $callback]
        );
    }

    public function testGetResumeUri()
    {
        $resumeUri = 'theResumeUri';
        $response = new Response(200, ['Location' => $resumeUri]);

        $this->requestWrapper->send(
            Argument::that(function (RequestInterface $request) {
                return $request->getHeader('X-Upload-Content-Length') === ['4'];
            }),
            Argument::type('array')
        )->willReturn($response);

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'http://www.example.com'
        );

        $this->assertEquals($resumeUri, $uploader->getResumeUri());
    }

    public function testResumeUploadReturnsIfAlreadySuccessful()
    {
        $response = new Response(200, [], $this->successBody);

        $this->requestWrapper->send(
            Argument::type(RequestInterface::class),
            Argument::type('array')
        )->willReturn($response);

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'http://www.example.com'
        );

        $this->assertEquals(
            json_decode($this->successBody, true),
            $uploader->resume('http://www.example.com/some-resume-uri')
        );
    }

    public function testResumesFailedUpload()
    {
        $response = new Response(200, [], $this->successBody);
        $statusResponse = new Response(200, ['Range' => 'bytes 0-2']);

        $this->requestWrapper->send(
            Argument::that(function ($request) {
                return $request->getHeaderLine('Content-Range') === 'bytes */4';
            }),
            Argument::type('array')
        )->willReturn($statusResponse);

        $this->requestWrapper->send(
            Argument::type(RequestInterface::class),
            Argument::type('array')
        )->willReturn($response);

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'http://www.example.com'
        );

        $this->assertEquals(
            json_decode($this->successBody, true),
            $uploader->resume('http://www.example.com/some-resume-uri')
        );
    }

    public function testResumeFinishedUpload()
    {
        $statusResponse = new Response(200, [], $this->successBody);

        $this->requestWrapper->send(
            Argument::type(RequestInterface::class),
            Argument::type('array')
        )->willReturn($statusResponse);

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'http://www.example.com'
        );

        $this->assertEquals(
            json_decode($this->successBody, true),
            $uploader->resume('http://www.example.com/some-resume-uri')
        );
    }

    /**
     * This tests whether retry related options are properly set in the
     * abstract uploader class. Since we already had these tests for
     * ResumableUploader class which extends the AbstractUploader class,
     * thus testing it here would be sufficient.
     */
    public function testRetryOptionsPassing()
    {
        $attempt = 0;
        $retryFunctionCalled = false;
        $retryListenerCalled = false;
        $requestWrapper = new RequestWrapper([
            'httpHandler' => function () use (&$attempt) {
                if ($attempt++ < 1) {
                    throw new \Exception('retry!');
                }
                return new Response(200, [], $this->successBody);
            },
            'authHttpHandler' => function () {
                return new Response(200, [], '{"access_token": "abc"}');
            },
        ]);
        $options = [
            'restRetryFunction' => function () use (&$retryFunctionCalled) {
                $retryFunctionCalled = true;
                return true;
            },
            'restRetryListener' => function () use (&$retryListenerCalled) {
                $retryListenerCalled = true;
            },
        ];
        $uploader = new ResumableUploader(
            $requestWrapper,
            $this->stream,
            'http://www.example.com',
            $options
        );
        $uploader->upload();

        $this->assertTrue($retryFunctionCalled);
        $this->assertTrue($retryListenerCalled);
    }

    public function testUploadSendsGoogHashOnFinalChunk()
    {
        $hashValue = 'crc32c=abc123';
        $resumeUri = 'http://some-resume-uri.example.com';

        $this->requestWrapper->send(
            Argument::which('getMethod', 'POST'),
            Argument::type('array')
        )->willReturn(new Response(200, ['Location' => $resumeUri]));

        $this->requestWrapper->send(
            Argument::that(function (RequestInterface $request) use ($hashValue) {
                return $request->getHeaderLine('X-Goog-Hash') === $hashValue;
            }),
            Argument::type('array')
        )->willReturn(new Response(200, [], $this->successBody));

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'http://www.example.com',
            [
                'restOptions' => [
                    'headers' => ['X-Goog-Hash' => $hashValue]
                ]
            ]
        );

        $this->assertEquals(json_decode($this->successBody, true), $uploader->upload());
    }

    public function testUploadDoesNotSendGoogHashOnIntermediateChunk()
    {
        $hashValue = 'crc32c=abc123';
        $resumeUri = 'http://some-resume-uri.example.com';

        $this->requestWrapper->send(
            Argument::which('getMethod', 'POST'),
            Argument::type('array')
        )->willReturn(new Response(200, ['Location' => $resumeUri]));

        $this->requestWrapper->send(
            Argument::that(function (RequestInterface $request) {
                return $request->getHeaderLine('Content-Range') === 'bytes 0-1/4'
                    && $request->getHeader('Content-Length') === ['2']
                    && !$request->hasHeader('X-Goog-Hash');
            }),
            Argument::type('array')
        )->willReturn(new Response(308, ['Range' => 'bytes 0-1']));

        $this->requestWrapper->send(
            Argument::that(function (RequestInterface $request) {
                return $request->getHeaderLine('Content-Range') === 'bytes 2-3/4';
            }),
            Argument::type('array')
        )->willReturn(new Response(200, [], $this->successBody));

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream, // size 4
            'http://www.example.com',
            [
                'chunkSize' => 2, // Force multi-chunk upload
                'restOptions' => [
                    'headers' => ['X-Goog-Hash' => $hashValue]
                ]
            ]
        );

        $this->assertEquals(json_decode($this->successBody, true), $uploader->upload());
    }

    public function testGoogHashOnlyOnFinalChunkOfMultiChunkUpload()
    {
        $hashValue = 'crc32c=abc123';
        $resumeUri = 'http://some-resume-uri.example.com';
        $this->stream = Utils::streamFor('01234567'); // 8 bytes total

        $this->requestWrapper->send(
            Argument::which('getMethod', 'POST'),
            Argument::type('array')
        )->willReturn(new Response(200, ['Location' => $resumeUri]));

        $this->requestWrapper->send(
            Argument::that(function (RequestInterface $request) {
                return $request->getHeaderLine('Content-Range') === 'bytes 0-3/8'
                    && !$request->hasHeader('X-Goog-Hash');
            }),
            Argument::type('array')
        )->willReturn(new Response(308, ['Range' => 'bytes 0-3']));

        $this->requestWrapper->send(
            Argument::that(function (RequestInterface $request) use ($hashValue) {
                return $request->getHeaderLine('Content-Range') === 'bytes 4-7/8'
                    && $request->getHeaderLine('X-Goog-Hash') === $hashValue;
            }),
            Argument::type('array')
        )->willReturn(new Response(200, [], $this->successBody));

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'http://www.example.com',
            [
                'chunkSize' => 4,
                'restOptions' => [
                    'headers' => ['X-Goog-Hash' => $hashValue]
                ]
            ]
        );

        $this->assertEquals(json_decode($this->successBody, true), $uploader->upload());
    }

    public function testThrowsExceptionWhenAttemptsAsyncUpload()
    {
        $this->expectException(GoogleException::class);

        $stream = $this->prophesize(StreamInterface::class);
        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $stream->reveal(),
            'http://www.example.com'
        );

        $uploader->uploadAsync();
    }

    public function testThrowsExceptionWhenResumingNonSeekableStream()
    {
        $this->expectException(GoogleException::class);

        $stream = $this->prophesize(StreamInterface::class);
        $stream->isSeekable()->willReturn(false);
        $stream->getMetadata('uri')->willReturn('blah');

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $stream->reveal(),
            'http://www.example.com'
        );

        $uploader->resume('http://www.example.com/some-resume-uri');
    }

    /**
     * @dataProvider validResumeUriProvider
     */
    public function testResumeAllowsMatchingOrigin($configuredUri, $resumeUri)
    {
        $statusResponse = new Response(200, [], $this->successBody);

        $this->requestWrapper->send(
            Argument::that(function (RequestInterface $request) use ($resumeUri) {
                return $request->getMethod() === 'PUT'
                    && (string) $request->getUri() === (string) Utils::uriFor($resumeUri);
            }),
            Argument::type('array')
        )->willReturn($statusResponse)->shouldBeCalledOnce();

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            $configuredUri
        );

        $this->assertSame(
            json_decode($this->successBody, true),
            $uploader->resume($resumeUri)
        );
    }

    public function validResumeUriProvider()
    {
        return [
            'exact match' => [
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?uploadType=resumable',
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?uploadType=resumable&upload_id=123',
            ],
            'case-insensitive scheme and host' => [
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?uploadType=resumable',
                'HTTPS://STORAGE.GOOGLEAPIS.COM/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
            'explicit default https port' => [
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?uploadType=resumable',
                'https://storage.googleapis.com:443/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
            'omitted default https port' => [
                'https://storage.googleapis.com:443/upload/storage/v1/b/my-bucket/o?uploadType=resumable',
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
            'explicit default http port' => [
                'http://example.com/upload',
                'http://example.com:80/upload?upload_id=123',
            ],
            'custom port emulator' => [
                'http://localhost:4443/upload/storage/v1/b/my-bucket/o?uploadType=resumable',
                'http://localhost:4443/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
            'ipv6 literal with custom port' => [
                'http://[::1]:4443/upload/storage/v1/b/my-bucket/o?uploadType=resumable',
                'http://[::1]:4443/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
            'custom host containing https substring' => [
                'https://https.example.com/upload/storage/v1/b/my-bucket/o?uploadType=resumable',
                'https://https.example.com/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
            'psr7 uri interface' => [
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?uploadType=resumable',
                new Uri('https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?upload_id=123'),
            ],
        ];
    }

    /**
     * @dataProvider invalidResumeUriProvider
     */
    public function testResumeRejectsInvalidOrMismatchedUri($configuredUri, $resumeUri)
    {
        $this->requestWrapper->send(
            Argument::any(),
            Argument::any()
        )->shouldNotBeCalled();

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            $configuredUri,
            [
                'restOptions' => [
                    'headers' => [
                        'x-goog-encryption-algorithm' => 'AES256',
                        'x-goog-encryption-key' => base64_encode(str_repeat('a', 32)),
                        'x-goog-encryption-key-sha256' => base64_encode(hash('sha256', str_repeat('a', 32), true)),
                    ],
                ],
            ]
        );

        $this->expectException(InvalidArgumentException::class);
        $uploader->resume($resumeUri);
    }

    public function invalidResumeUriProvider()
    {
        $defaultEndpoint = 'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?uploadType=resumable';

        return [
            'different host' => [
                $defaultEndpoint,
                'https://example.invalid/upload-session',
            ],
            'scheme downgrade' => [
                $defaultEndpoint,
                'http://storage.googleapis.com/upload-session',
            ],
            'scheme upgrade' => [
                'http://localhost:4443/upload',
                'https://localhost:4443/upload-session',
            ],
            'non-http scheme' => [
                $defaultEndpoint,
                'file://storage.googleapis.com/etc/passwd',
            ],
            'different port' => [
                $defaultEndpoint,
                'https://storage.googleapis.com:8443/upload-session',
            ],
            'wrong default port for https' => [
                $defaultEndpoint,
                'https://storage.googleapis.com:80/upload-session',
            ],
            'missing custom port' => [
                'http://localhost:4443/upload',
                'http://localhost/upload-session',
            ],
            'subdomain suffix spoof' => [
                $defaultEndpoint,
                'https://storage.googleapis.com.evil.com/upload-session',
            ],
            'subdomain prefix spoof' => [
                $defaultEndpoint,
                'https://evil.storage.googleapis.com/upload-session',
            ],
            'trailing dot host' => [
                $defaultEndpoint,
                'https://storage.googleapis.com./upload-session',
            ],
            'userinfo host confusion' => [
                $defaultEndpoint,
                'https://storage.googleapis.com@example.invalid/upload-session',
            ],
            'unexpected userinfo' => [
                $defaultEndpoint,
                'https://user:pass@storage.googleapis.com/upload-session',
            ],
            'backslash userinfo parser differential' => [
                $defaultEndpoint,
                'https://example.invalid\\@storage.googleapis.com/upload-session',
            ],
            'backslash in host' => [
                $defaultEndpoint,
                'https://storage.googleapis.com\\example.invalid/upload-session',
            ],
            'double scheme' => [
                $defaultEndpoint,
                'https://https://storage.googleapis.com/upload-session',
            ],
            'protocol-relative uri' => [
                $defaultEndpoint,
                '//storage.googleapis.com/upload-session',
            ],
            'relative path' => [
                $defaultEndpoint,
                '/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
            'bare string' => [
                $defaultEndpoint,
                'theResumeUri',
            ],
            'empty string' => [
                $defaultEndpoint,
                '',
            ],
            'control characters' => [
                $defaultEndpoint,
                "https://storage.googleapis.com/upload\r\nX-Injected: 1",
            ],
            'malformed uri' => [
                $defaultEndpoint,
                'https://:443/upload',
            ],
            'null resume uri' => [
                $defaultEndpoint,
                null,
            ],
            'configured endpoint missing scheme and host' => [
                '/relative/upload',
                'https://storage.googleapis.com/upload-session',
            ],
        ];
    }

    public function testResumeDoesNotMutateResumeUriOnValidationFailure()
    {
        $validResumeUri = 'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?upload_id=valid';
        $this->requestWrapper->send(
            Argument::that(function (RequestInterface $request) {
                return $request->getMethod() === 'POST'
                    && $request->getUri()->getHost() === 'storage.googleapis.com';
            }),
            Argument::type('array')
        )->willReturn(new Response(200, ['Location' => $validResumeUri]))->shouldBeCalledOnce();

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?uploadType=resumable'
        );

        try {
            $uploader->resume('https://example.invalid/upload-session');
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('https://storage.googleapis.com', $e->getMessage());
        }

        $this->assertSame($validResumeUri, $uploader->getResumeUri());
    }

    public function testThrowsExceptionWithFailedUpload()
    {
        $this->expectException(GoogleException::class);

        $resumeUriResponse = new Response(200, ['Location' => 'theResumeUri']);

        $this->requestWrapper->send(
            Argument::which('getMethod', 'POST'),
            Argument::type('array')
        )->willReturn($resumeUriResponse);

        $this->requestWrapper->send(
            Argument::which('getMethod', 'PUT'),
            Argument::type('array')
        )->willThrow(GoogleException::class);

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'http://www.example.com'
        );

        $uploader->upload();
    }

    /**
     * @dataProvider rangeHeaderProvider
     */
    public function testGetRangeStart($rangeHeader, $expectedRangeStart)
    {
        $method = new \ReflectionMethod(ResumableUploader::class, 'getRangeStart');

        $uploader = $this->createMock(ResumableUploader::class);

        $actualRangeStart = $method->invoke($uploader, $rangeHeader);
        $this->assertEquals($expectedRangeStart, $actualRangeStart);
    }

    public function rangeHeaderProvider()
    {
        return
        [
            // range header, expected range start
            ['', 0],
            ['bytes 0-3/4', 4],
            ['bytes 0-99/100', 100],
            ['bytes 100-199/200', 200],
            ['bytes=2000-',1]
        ];
    }
}
