<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El disco `s3` apunta a DigitalOcean Spaces: las fotos van dentro de la
 * carpeta del bucket (AWS_ROOT) y deben servirse por la URL de la CDN.
 * url() no hace red, así que se puede comprobar sin credenciales reales.
 */
class SpacesDiskConfigTest extends TestCase
{
    public function test_urls_include_the_bucket_folder_and_use_the_cdn_host(): void
    {
        config([
            'filesystems.disks.s3.key' => 'test',
            'filesystems.disks.s3.secret' => 'test',
            'filesystems.disks.s3.region' => 'nyc3',
            'filesystems.disks.s3.bucket' => 'demo-bucket',
            'filesystems.disks.s3.endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'filesystems.disks.s3.url' => 'https://demo-bucket.nyc3.cdn.digitaloceanspaces.com',
            'filesystems.disks.s3.root' => 'pos-images',
        ]);
        Storage::forgetDisk('s3');

        $this->assertSame(
            'https://demo-bucket.nyc3.cdn.digitaloceanspaces.com/pos-images/stores/7/abc.webp',
            Storage::disk('s3')->url('stores/7/abc.webp'),
        );
    }

    public function test_uploaded_files_are_cacheable_forever(): void
    {
        $this->assertStringContainsString(
            'immutable',
            (string) config('filesystems.disks.s3.options.CacheControl'),
        );
    }
}
