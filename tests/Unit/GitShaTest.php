<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/notam/map-layer.php';

/**
 * Deploy SHA resolution for footers and NOTAM map build tokens.
 */
class GitShaTest extends TestCase
{
    private string $originalGitSha;

    private string|false $originalDeployFileEnv;

    private string $tempFile;

    protected function setUp(): void
    {
        parent::setUp();
        $gitSha = getenv('GIT_SHA');
        $this->originalGitSha = $gitSha === false ? '' : $gitSha;
        $this->originalDeployFileEnv = getenv('AVIATIONWX_DEPLOY_GIT_SHA_FILE');
        $this->tempFile = sys_get_temp_dir() . '/aviationwx-deploy-git-sha-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->originalGitSha === '') {
            putenv('GIT_SHA');
        } else {
            putenv('GIT_SHA=' . $this->originalGitSha);
        }

        if ($this->originalDeployFileEnv === false) {
            putenv('AVIATIONWX_DEPLOY_GIT_SHA_FILE');
        } else {
            putenv('AVIATIONWX_DEPLOY_GIT_SHA_FILE=' . $this->originalDeployFileEnv);
        }

        if (is_file($this->tempFile)) {
            @unlink($this->tempFile);
        }

        parent::tearDown();
    }

    public function testNormalizeGitShaShort_TrimsAndTruncates(): void
    {
        $this->assertSame('9f34715', normalizeGitShaShort("9f3471525bc39e8a\n"));
        $this->assertSame('abcdef1', normalizeGitShaShort('abcdef12'));
        $this->assertSame('', normalizeGitShaShort('abc'));
        $this->assertSame('', normalizeGitShaShort(''));
        $this->assertSame('', normalizeGitShaShort("   \n"));
    }

    public function testGetDeployGitShaFilePath_DefaultUnderCache(): void
    {
        putenv('AVIATIONWX_DEPLOY_GIT_SHA_FILE');
        $path = getDeployGitShaFilePath();
        $this->assertStringEndsWith('/cache/.deploy-git-sha', $path);
        $this->assertSame(CACHE_DEPLOY_GIT_SHA_FILE, $path);
    }

    public function testGetDeployGitShaFilePath_OverrideEnv(): void
    {
        putenv('AVIATIONWX_DEPLOY_GIT_SHA_FILE=' . $this->tempFile);
        $this->assertSame($this->tempFile, getDeployGitShaFilePath());
    }

    public function testReadDeployGitShaFile_ReturnsShortShaFromFile(): void
    {
        putenv('AVIATIONWX_DEPLOY_GIT_SHA_FILE=' . $this->tempFile);
        file_put_contents($this->tempFile, "9f3471525bc39e8a\n");

        $this->assertSame('9f34715', readDeployGitShaFile());
    }

    public function testReadDeployGitShaFile_MissingOrShort_ReturnsEmpty(): void
    {
        putenv('AVIATIONWX_DEPLOY_GIT_SHA_FILE=' . $this->tempFile . '-absent');
        $this->assertSame('', readDeployGitShaFile());

        putenv('AVIATIONWX_DEPLOY_GIT_SHA_FILE=' . $this->tempFile);
        file_put_contents($this->tempFile, "abc\n");
        $this->assertSame('', readDeployGitShaFile());
    }

    public function testGetGitSha_PrefersEnvironmentOverFile(): void
    {
        putenv('GIT_SHA=eeeeeee1');
        putenv('AVIATIONWX_DEPLOY_GIT_SHA_FILE=' . $this->tempFile);
        file_put_contents($this->tempFile, "fffffff2\n");

        $this->assertSame('eeeeeee', getGitSha());
    }

    public function testGetGitSha_ReadsDeployFileWhenEnvMissing(): void
    {
        putenv('GIT_SHA');
        putenv('AVIATIONWX_DEPLOY_GIT_SHA_FILE=' . $this->tempFile);
        file_put_contents($this->tempFile, "9f3471525bc39e8a\n");

        $this->assertSame('9f34715', getGitSha());
    }

    /**
     * Cron-restarted workers lack GIT_SHA; map tokens must still match Apache via the file.
     */
    public function testNotamMapBuildToken_UsesDeployFileWhenEnvMissing(): void
    {
        putenv('GIT_SHA');
        putenv('AVIATIONWX_DEPLOY_GIT_SHA_FILE=' . $this->tempFile);
        file_put_contents($this->tempFile, "abcdef12\n");

        $this->assertSame(
            'abcdef1-v' . NOTAM_TFR_MAP_LAYER_LOGIC_VERSION,
            notamTfrMapLayerCurrentBuildToken()
        );
    }

    public function testEntrypoint_PersistsDeployGitShaFile(): void
    {
        $entrypoint = file_get_contents(dirname(__DIR__, 2) . '/docker/docker-entrypoint.sh');
        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('.deploy-git-sha', $entrypoint);
        $this->assertStringContainsString('DEPLOY_GIT_SHA_FILE', $entrypoint);
        $this->assertMatchesRegularExpression(
            '/DEPLOY_GIT_SHA_FILE=.*\.deploy-git-sha/s',
            $entrypoint
        );
        $this->assertMatchesRegularExpression(
            '/if \[ -n "\$\{GIT_SHA:-\}" \]; then/s',
            $entrypoint
        );
        $this->assertStringContainsString('rm -f "${DEPLOY_GIT_SHA_FILE}"', $entrypoint);
    }
}
