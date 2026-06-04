<?php
/**
 * Copyright 2026 Google Inc. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Google\Cloud\Storage\Tests\System;

use Google\Cloud\Core\Exception\BadRequestException;
use Google\Cloud\Core\Exception\ServiceException;
use Google\Cloud\Storage\Bucket;

/**
 * @group storage
 * @group storage-ipfilter
 */
class BucketIpFilterTest extends StorageTestCase
{
    public function testCreateBucketWithIpFilterDisabled()
    {
        $ipFilterConfig = [
            'mode' => 'Disabled',
            'publicNetworkSource' => [
                'allowedIpCidrRanges' => ['1.2.3.0/24']
            ],
            'vpcNetworkSources' => [
                [
                    'network' => 'projects/dummy-project/global/networks/dummy-network',
                    'allowedIpCidrRanges' => ['10.0.0.0/24']
                ]
            ],
            'allowCrossOrgVpcs' => true,
            'allowAllServiceAgentAccess' => true
        ];

        $bucketName = uniqid(self::TESTING_PREFIX);
        $bucket = self::createBucket(
            self::$client,
            $bucketName,
            ['ipFilter' => $ipFilterConfig]
        );

        $info = $bucket->info();
        $this->assertArrayHasKey('ipFilter', $info);
        $this->assertEquals('Disabled', $info['ipFilter']['mode']);
        $this->assertEquals(['1.2.3.0/24'], $info['ipFilter']['publicNetworkSource']['allowedIpCidrRanges']);
        $this->assertEquals(
            'projects/dummy-project/global/networks/dummy-network',
            $info['ipFilter']['vpcNetworkSources'][0]['network']
        );
        $this->assertEquals(['10.0.0.0/24'], $info['ipFilter']['vpcNetworkSources'][0]['allowedIpCidrRanges']);
        $this->assertTrue($info['ipFilter']['allowAllServiceAgentAccess']);
        $this->assertTrue($info['ipFilter']['allowCrossOrgVpcs']);

        return $bucket;
    }

    /**
     * @depends testCreateBucketWithIpFilterDisabled
     */
    public function testUpdateIpFilterDisabled(Bucket $bucket)
    {
        $ipFilterConfig = [
            'mode' => 'Disabled',
            'publicNetworkSource' => [
                'allowedIpCidrRanges' => ['1.2.3.0/24', '5.6.7.0/24']
            ],
            'allowAllServiceAgentAccess' => false
        ];

        $bucket->update(['ipFilter' => $ipFilterConfig]);
        $info = $bucket->reload();

        $this->assertArrayHasKey('ipFilter', $info);
        $this->assertEquals('Disabled', $info['ipFilter']['mode']);
        $this->assertEquals(['1.2.3.0/24', '5.6.7.0/24'], $info['ipFilter']['publicNetworkSource']['allowedIpCidrRanges']);
        $this->assertFalse($info['ipFilter']['allowAllServiceAgentAccess']);
    }

    public function testCreateBucketWithInvalidIpFilterFails()
    {
        $this->expectException(BadRequestException::class);

        $ipFilterConfig = [
            'mode' => 'Disabled',
            'publicNetworkSource' => [
                // Host bits must be zero for /24 range, so 1.2.3.4/24 is invalid
                'allowedIpCidrRanges' => ['1.2.3.4/24']
            ],
            'allowAllServiceAgentAccess' => true
        ];

        self::createBucket(
            self::$client,
            uniqid(self::TESTING_PREFIX),
            [
                'ipFilter' => $ipFilterConfig,
                'retries' => 0,
                'sysTestRetries' => 0
            ]
        );
    }

    public function testUpdateBucketWithInvalidIpFilterFails()
    {
        $bucket = self::createBucket(self::$client, uniqid(self::TESTING_PREFIX));

        $this->expectException(BadRequestException::class);

        $ipFilterConfig = [
            'mode' => 'Disabled',
            'publicNetworkSource' => [
                'allowedIpCidrRanges' => ['1.2.3.4/24']
            ],
            'allowAllServiceAgentAccess' => true
        ];

        $bucket->update(['ipFilter' => $ipFilterConfig]);
    }

    public function testBucketAccessWithoutIpFilter()
    {
        $bucketName = uniqid(self::TESTING_PREFIX);
        $bucket = self::createBucket(self::$client, $bucketName);

        $this->assertArrayNotHasKey('ipFilter', $bucket->info());

        $objectName = 'test_no_ip_filter.txt';
        $objectContent = 'hello world';

        $object = $bucket->upload($objectContent, ['name' => $objectName]);
        $this->assertEquals($objectContent, $object->downloadAsString());

        $unauthBucket = self::$unauthenticatedClient->bucket($bucketName);
        $unauthObject = $unauthBucket->object($objectName);

        try {
            $unauthObject->downloadAsString();
            $this->fail('Expected anonymous download to fail.');
        } catch (ServiceException $e) {
            $this->assertEquals(401, $e->getCode());
            $this->assertStringContainsString('Anonymous caller', $e->getMessage());
        }

        $object->delete();
        $bucket->delete();
    }

    public function testBucketAccessWhenEnforced()
    {
        $iam = self::$bucket->iam();
        $isExempt = !empty($iam->testPermissions(['storage.buckets.exemptFromIpFilter']));

        if (!$isExempt) {
            $this->markTestSkipped(
                'Runner is not exempt from IP filter. Skipping lockout tests to prevent orphaned resources.'
            );
        }

        $bucketName = uniqid(self::TESTING_PREFIX);
        $ipFilterConfig = [
            'mode' => 'Enabled',
            'publicNetworkSource' => [
                'allowedIpCidrRanges' => ['1.1.1.1/32'] // Blocked IP for runner
            ],
            'allowAllServiceAgentAccess' => true
        ];

        $bucket = self::createBucket(
            self::$client,
            $bucketName,
            ['ipFilter' => $ipFilterConfig]
        );

        $objectName = 'test_ip_filter.txt';
        $objectContent = 'hello world';

        $object = $bucket->upload($objectContent, ['name' => $objectName]);
        $this->assertEquals($objectContent, $object->downloadAsString());

        $unauthBucket = self::$unauthenticatedClient->bucket($bucketName);
        $unauthObject = $unauthBucket->object($objectName);

        try {
            $unauthObject->downloadAsString();
            $this->fail('Expected unauthenticated download to fail due to IP filter.');
        } catch (ServiceException $e) {
            $this->assertEquals(403, $e->getCode());
            $this->assertStringContainsString('IP filtering', $e->getMessage());
        }

        $object->delete();
        $bucket->delete();
    }
}
