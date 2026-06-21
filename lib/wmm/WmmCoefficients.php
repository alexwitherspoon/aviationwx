<?php

declare(strict_types=1);

/**
 * Parsed NOAA WMM coefficient file (WMM.COF).
 *
 * Loads Gauss coefficients and secular variation terms for spherical harmonic synthesis.
 *
 * @see https://www.ncei.noaa.gov/products/world-magnetic-model/wmm-coefficients
 */
final class WmmCoefficients
{
    public const MAX_DEGREE = 12;

    private float $epoch;

    private string $modelName;

    private string $releaseDate;

    /** @var array<int, array<int, float>> Main field g (nT), indexed [m][n] */
    private array $g = [];

    /** @var array<int, array<int, float>> Main field h (nT), indexed [n][m-1] for m > 0 */
    private array $h = [];

    /** @var array<int, array<int, float>> Secular variation dg (nT/yr), indexed [m][n] */
    private array $dg = [];

    /** @var array<int, array<int, float>> Secular variation dh (nT/yr), indexed [n][m-1] for m > 0 */
    private array $dh = [];

    /** @var array<int, float> Schmidt quasi-normalized factors (flat index n + m * harmonicStride) */
    private array $snorm = [];

    /** @var array<int, array<int, float>> Recursion coefficients k[m][n] */
    private array $k = [];

    /** @var array<int, float> */
    private array $fn = [];

    /** @var array<int, float> */
    private array $fm = [];

    /**
     * @param string $cofPath Absolute path to WMM.COF
     * @throws \InvalidArgumentException When the file is missing or malformed
     */
    public function __construct(string $cofPath)
    {
        if (!is_readable($cofPath)) {
            throw new \InvalidArgumentException('WMM coefficient file is not readable: ' . $cofPath);
        }

        $this->initializeArrays();
        $this->parseCofFile($cofPath);
        $this->applySchmidtNormalization();
    }

    /**
     * Load bundled WMM coefficients from data/wmm/WMM.COF.
     */
    public static function fromBundledPath(): self
    {
        return new self(self::getBundledCofPath());
    }

    /**
     * Absolute path to the vendored WMM.COF file.
     *
     * @return string Absolute filesystem path
     */
    public static function getBundledCofPath(): string
    {
        return dirname(__DIR__, 2) . '/data/wmm/WMM.COF';
    }

    /**
     * Absolute path to the vendored manifest.json file.
     *
     * @return string Absolute filesystem path
     */
    public static function getBundledManifestPath(): string
    {
        return dirname(__DIR__, 2) . '/data/wmm/manifest.json';
    }

    /**
     * Model epoch year from the coefficient file header.
     *
     * @return float Decimal year (for example 2025.0)
     */
    public function getEpoch(): float
    {
        return $this->epoch;
    }

    /**
     * Model name from the coefficient file header.
     *
     * @return string Model identifier (for example WMM-2025)
     */
    public function getModelName(): string
    {
        return $this->modelName;
    }

    /**
     * Coefficient release date from the file header.
     *
     * @return string Release date string from NOAA (for example 11/13/2024)
     */
    public function getReleaseDate(): string
    {
        return $this->releaseDate;
    }

    /**
     * Maximum spherical harmonic degree supported by this parser.
     *
     * @return int Harmonic degree (12 for WMM)
     */
    public function getMaxDegree(): int
    {
        return self::MAX_DEGREE;
    }

    /**
     * Stride for flat Legendre factor indexing (max degree + 1).
     *
     * @return int Index stride for snorm flat layout
     */
    public function getHarmonicStride(): int
    {
        return self::MAX_DEGREE + 1;
    }

    /**
     * Schmidt-normalized main-field g coefficients.
     *
     * @return array<int, array<int, float>>
     */
    public function getG(): array
    {
        return $this->copyMatrix($this->g);
    }

    /**
     * Schmidt-normalized main-field h coefficients.
     *
     * @return array<int, array<int, float>>
     */
    public function getH(): array
    {
        return $this->copyMatrix($this->h);
    }

    /**
     * Schmidt-normalized secular variation g coefficients.
     *
     * @return array<int, array<int, float>>
     */
    public function getDg(): array
    {
        return $this->copyMatrix($this->dg);
    }

    /**
     * Schmidt-normalized secular variation h coefficients.
     *
     * @return array<int, array<int, float>>
     */
    public function getDh(): array
    {
        return $this->copyMatrix($this->dh);
    }

    /**
     * Precomputed Schmidt quasi-normalized factors.
     *
     * @return array<int, float>
     */
    public function getSnorm(): array
    {
        return [...$this->snorm];
    }

    /**
     * Associated Legendre recursion coefficients.
     *
     * @return array<int, array<int, float>>
     */
    public function getK(): array
    {
        return $this->copyMatrix($this->k);
    }

    /**
     * Radial harmonic scaling factors by degree n.
     *
     * @return array<int, float>
     */
    public function getFn(): array
    {
        return [...$this->fn];
    }

    /**
     * Azimuthal harmonic scaling factors by order m.
     *
     * @return array<int, float>
     */
    public function getFm(): array
    {
        return [...$this->fm];
    }

    private function initializeArrays(): void
    {
        $size = self::MAX_DEGREE + 1;
        for ($i = 0; $i < $size; $i++) {
            $this->g[$i] = array_fill(0, $size, 0.0);
            $this->h[$i] = array_fill(0, $size, 0.0);
            $this->dg[$i] = array_fill(0, $size, 0.0);
            $this->dh[$i] = array_fill(0, $size, 0.0);
            $this->k[$i] = array_fill(0, $size, 0.0);
            $this->fn[$i] = 0.0;
            $this->fm[$i] = 0.0;
        }
        $snormSize = $size * $size;
        $this->snorm = array_fill(0, $snormSize, 0.0);
        $this->snorm[0] = 1.0;
    }

    private function parseCofFile(string $cofPath): void
    {
        $lines = file($cofPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \InvalidArgumentException('Failed to read WMM coefficient file: ' . $cofPath);
        }

        $headerParsed = false;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '9999')) {
                break;
            }

            $parts = preg_split('/\s+/', $line);
            if ($parts === false || $parts === []) {
                continue;
            }

            if (!$headerParsed && count($parts) <= 3) {
                $this->epoch = (float) $parts[0];
                $this->modelName = $parts[1] ?? 'WMM';
                $this->releaseDate = $parts[2] ?? '';
                $headerParsed = true;
                continue;
            }

            if (count($parts) < 6) {
                continue;
            }

            $n = (int) $parts[0];
            $m = (int) $parts[1];
            if ($m > $n || $n > self::MAX_DEGREE) {
                continue;
            }

            $gnm = (float) $parts[2];
            $hnm = (float) $parts[3];
            $dgnm = (float) $parts[4];
            $dhnm = (float) $parts[5];

            $this->g[$m][$n] = $gnm;
            $this->dg[$m][$n] = $dgnm;
            if ($m !== 0) {
                $this->h[$n][$m - 1] = $hnm;
                $this->dh[$n][$m - 1] = $dhnm;
            }
        }

        if (!$headerParsed) {
            throw new \InvalidArgumentException('WMM coefficient file missing header line');
        }
    }

    private function applySchmidtNormalization(): void
    {
        $maxord = self::MAX_DEGREE;
        $stride = $this->getHarmonicStride();
        $this->snorm[0] = 1.0;

        for ($n = 1; $n <= $maxord; $n++) {
            $this->snorm[$n] = $this->snorm[$n - 1] * (2 * $n - 1) / $n;
            $j = 2;
            for ($m = 0; $m <= $n; $m++) {
                $this->k[$m][$n] = (float) (($n - 1) * ($n - 1) - $m * $m)
                    / (float) ((2 * $n - 1) * (2 * $n - 3));
                if ($m > 0) {
                    $flnmj = (($n - $m + 1) * $j) / (float) ($n + $m);
                    $this->snorm[$n + $m * $stride] = $this->snorm[$n + ($m - 1) * $stride] * sqrt($flnmj);
                    $j = 1;
                    $this->h[$n][$m - 1] *= $this->snorm[$n + $m * $stride];
                    $this->dh[$n][$m - 1] *= $this->snorm[$n + $m * $stride];
                }
                $this->g[$m][$n] *= $this->snorm[$n + $m * $stride];
                $this->dg[$m][$n] *= $this->snorm[$n + $m * $stride];
            }
            $this->fn[$n] = $n + 1;
            $this->fm[$n] = $n;
        }
        $this->k[1][1] = 0.0;
    }

    /**
     * @param array<int, array<int, float>> $matrix
     * @return array<int, array<int, float>>
     */
    private function copyMatrix(array $matrix): array
    {
        return array_map(static fn(array $row): array => [...$row], $matrix);
    }
}
