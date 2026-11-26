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
use Psr\Http\Message\UriInterface;
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
            Argument::that(function (RequestInterface $request) {
                return $request->getMethod() === 'PUT'
                    && (string) $request->getUri() === 'http://www.example.com/some-resume-uri'
                    && $request->getHeaderLine('Content-Range') === 'bytes */4';
            }),
            Argument::type('array')
        )->willReturn($statusResponse)->shouldBeCalledOnce();

        $this->requestWrapper->send(
            Argument::that(function (RequestInterface $request) {
                return $request->getMethod() === 'PUT'
                    && (string) $request->getUri() === 'http://www.example.com/some-resume-uri'
                    && $request->getHeaderLine('Content-Range') === 'bytes 3-3/4';
            }),
            Argument::type('array')
        )->willReturn($response)->shouldBeCalledOnce();

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

        $uploader->resume('http://some-resume-uri.example.com');
    }

    /**
     * @dataProvider validResumeUriProvider
     */
    public function testResumeAllowsMatchingOrigin($configuredUri, $resumeUri, string $expectedSentUri)
    {
        $statusResponse = new Response(200, [], $this->successBody);

        $this->requestWrapper->send(
            Argument::that(function (RequestInterface $request) use ($expectedSentUri) {
                return $request->getMethod() === 'PUT'
                    && (string) $request->getUri() === $expectedSentUri;
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
        $this->assertSame($expectedSentUri, $uploader->getResumeUri());
    }

    public function validResumeUriProvider()
    {
        $defaultEndpoint = 'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?uploadType=resumable';

        return [
            'exact match preserving gcs upload_id chars' => [
                $defaultEndpoint,
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?upload_id=ADPycd-1_2+3%2F4=',
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?upload_id=ADPycd-1_2+3%2F4=',
            ],
            'case-insensitive scheme and host' => [
                $defaultEndpoint,
                'HTTPS://STORAGE.GOOGLEAPIS.COM/upload/storage/v1/b/my-bucket/o?upload_id=123',
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
            'explicit default https port' => [
                $defaultEndpoint,
                'https://storage.googleapis.com:443/upload/storage/v1/b/my-bucket/o?upload_id=123',
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
            'omitted default https port' => [
                'https://storage.googleapis.com:443/upload/storage/v1/b/my-bucket/o?uploadType=resumable',
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?upload_id=123',
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
            'explicit default http port' => [
                'http://example.com/upload',
                'http://example.com:80/upload?upload_id=123',
                'http://example.com/upload?upload_id=123',
            ],
            'custom port emulator' => [
                'http://localhost:4443/upload/storage/v1/b/my-bucket/o?uploadType=resumable',
                'http://localhost:4443/upload/storage/v1/b/my-bucket/o?upload_id=123',
                'http://localhost:4443/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
            'ipv6 literal with custom port' => [
                'http://[::1]:4443/upload/storage/v1/b/my-bucket/o?uploadType=resumable',
                'http://[::1]:4443/upload/storage/v1/b/my-bucket/o?upload_id=123',
                'http://[::1]:4443/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
            'psr7 uri interface' => [
                $defaultEndpoint,
                new Uri('https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?upload_id=123'),
                'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?upload_id=123',
            ],
        ];
    }

    public function testResumeAllowsNonGuzzleUriInterfaceWithExplicitDefaultPort()
    {
        $customUri = $this->createMock(UriInterface::class);
        $customUri->method('getScheme')->willReturn('https');
        $customUri->method('getHost')->willReturn('storage.googleapis.com');
        $customUri->method('getPort')->willReturn(443);
        $customUri->method('getUserInfo')->willReturn('');
        $customUri->method('__toString')
            ->willReturn('https://storage.googleapis.com:443/upload/storage/v1/b/my-bucket/o?upload_id=123');

        $expectedSentUri = 'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?upload_id=123';
        $this->requestWrapper->send(
            Argument::that(function (RequestInterface $request) use ($expectedSentUri) {
                return $request->getMethod() === 'PUT'
                    && (string) $request->getUri() === $expectedSentUri;
            }),
            Argument::type('array')
        )->willReturn(new Response(200, [], $this->successBody))->shouldBeCalledOnce();

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?uploadType=resumable'
        );

        $this->assertSame(json_decode($this->successBody, true), $uploader->resume($customUri));
        $this->assertSame($expectedSentUri, $uploader->getResumeUri());
    }

    /**
     * @dataProvider invalidResumeUriProvider
     */
    public function testResumeRejectsInvalidOrMismatchedUri($configuredUri, $resumeUri, string $expectedMessage)
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
                        'x-goog-encryption-key' => 'secret-csek-key',
                    ],
                ],
            ]
        );

        try {
            $uploader->resume($resumeUri);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame($expectedMessage, $e->getMessage());
            $this->assertNull($e->getPrevious());
        }
    }

    public function invalidResumeUriProvider()
    {
        $defaultEndpoint = 'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?uploadType=resumable';
        $defaultOriginMsg = 'Resume URI origin does not match the configured upload endpoint origin '
            . '(https://storage.googleapis.com).';

        return [
            'different host' => [
                $defaultEndpoint,
                'https://example.invalid/upload-session?upload_id=secret-token',
                $defaultOriginMsg,
            ],
            'cross-universe domain' => [
                'https://storage.my-universe.example/upload/storage/v1/b/my-bucket/o?uploadType=resumable',
                'https://storage.googleapis.com/upload-session',
                'Resume URI origin does not match the configured upload endpoint origin '
                    . '(https://storage.my-universe.example).',
            ],
            'scheme downgrade' => [
                $defaultEndpoint,
                'http://storage.googleapis.com/upload-session',
                $defaultOriginMsg,
            ],
            'different port' => [
                $defaultEndpoint,
                'https://storage.googleapis.com:8443/upload-session',
                $defaultOriginMsg,
            ],
            'missing custom port' => [
                'http://localhost:4443/upload',
                'http://localhost/upload-session',
                'Resume URI origin does not match the configured upload endpoint origin (http://localhost:4443).',
            ],
            'subdomain suffix spoof' => [
                $defaultEndpoint,
                'https://storage.googleapis.com.evil.com/upload-session',
                $defaultOriginMsg,
            ],
            'trailing dot host' => [
                $defaultEndpoint,
                'https://storage.googleapis.com./upload-session',
                $defaultOriginMsg,
            ],
            'userinfo host confusion' => [
                $defaultEndpoint,
                'https://storage.googleapis.com@example.invalid/upload-session',
                $defaultOriginMsg,
            ],
            'unexpected userinfo' => [
                $defaultEndpoint,
                'https://user:secret@storage.googleapis.com/upload-session',
                'Resume URI userinfo does not match the configured upload endpoint.',
            ],
            'backslash userinfo parser differential' => [
                $defaultEndpoint,
                'https://example.invalid\\@storage.googleapis.com/upload-session',
                'Resume URI userinfo does not match the configured upload endpoint.',
            ],
            'non-http scheme on resume uri' => [
                $defaultEndpoint,
                'file://storage.googleapis.com/etc/passwd',
                'Resume URI must include a valid HTTP(S) scheme and host.',
            ],
            'non-http scheme on both endpoints' => [
                'file://storage.googleapis.com/upload',
                'file://storage.googleapis.com/upload-session',
                'Configured upload endpoint must include a valid HTTP(S) scheme and host.',
            ],
            'protocol-relative uri' => [
                $defaultEndpoint,
                '//storage.googleapis.com/upload-session',
                'Resume URI must include a valid HTTP(S) scheme and host.',
            ],
            'relative path' => [
                $defaultEndpoint,
                '/upload/storage/v1/b/my-bucket/o?upload_id=123',
                'Resume URI must include a valid HTTP(S) scheme and host.',
            ],
            'empty string' => [
                $defaultEndpoint,
                '',
                'Resume URI must be a non-empty string or UriInterface.',
            ],
            'null resume uri' => [
                $defaultEndpoint,
                null,
                'Resume URI must be a non-empty string or UriInterface.',
            ],
            'control characters' => [
                $defaultEndpoint,
                "https://storage.googleapis.com/upload\r\nX-Injected: 1",
                'Resume URI must not contain control characters.',
            ],
            'malformed resume uri redacts secrets' => [
                $defaultEndpoint,
                'https://user:secret@:443/upload?upload_id=secret-token&X-Goog-Signature=secret-sig',
                'Resume URI is not a valid URI.',
            ],
            'malformed configured endpoint redacts secrets' => [
                'https://:443/upload?X-Goog-Signature=secret-sig',
                'https://storage.googleapis.com/upload-session',
                'Configured upload endpoint is not a valid URI.',
            ],
            'configured endpoint missing scheme and host' => [
                '/relative/upload',
                'https://storage.googleapis.com/upload-session',
                'Configured upload endpoint must include a valid HTTP(S) scheme and host.',
            ],
        ];
    }

    public function testResumeRejectsSpoofedUriInterface()
    {
        $this->requestWrapper->send(
            Argument::any(),
            Argument::any()
        )->shouldNotBeCalled();

        $spoofedUri = $this->createMock(UriInterface::class);
        $spoofedUri->method('getScheme')->willReturn('https');
        $spoofedUri->method('getHost')->willReturn('storage.googleapis.com');
        $spoofedUri->method('getPort')->willReturn(null);
        $spoofedUri->method('getUserInfo')->willReturn('');
        $spoofedUri->method('__toString')->willReturn('https://storage.googleapis.com.evil.com/upload-session');

        $uploader = new ResumableUploader(
            $this->requestWrapper->reveal(),
            $this->stream,
            'https://storage.googleapis.com/upload/storage/v1/b/my-bucket/o?uploadType=resumable'
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Resume URI origin does not match the configured upload endpoint origin (https://storage.googleapis.com).'
        );

        $uploader->resume($spoofedUri);
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

        // Fails before any resume URI is set; does not poison state.
        try {
            $uploader->resume('https://example.invalid/upload-session');
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('https://storage.googleapis.com', $e->getMessage());
        }

        $this->assertSame($validResumeUri, $uploader->getResumeUri());

        // Fails after a valid resume URI is established; preserves existing URI without another POST.
        try {
            $uploader->resume('https://example.invalid/another-session');
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
