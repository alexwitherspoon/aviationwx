<?php
/**
 * Minimal classic netCDF reader (CDF-1)
 *
 * Reads the netCDF classic binary format used by MADIS surface observation
 * files. The full spec covers CDF-1, CDF-2 (64-bit offsets), and CDF-5;
 * MADIS HFMETAR files are CDF-1, so this reader targets that layout and
 * rejects the other variants loudly rather than misreading offsets.
 *
 * Layout summary (all integers big-endian):
 * - Magic "CDF\x01", then numrecs (uint32)
 * - Dimension list, global attribute list, variable list
 * - Variable data: non-record variables first, then record data as slabs
 *   of recordSize bytes, one per record
 *
 * Record variables (those that include the unlimited dimension) store one
 * slab per record. Each slab is padded to a 4-byte boundary; recordSize is
 * the sum of those padded sizes across record variables in header order.
 *
 * @package AviationWX\Madis
 */

namespace AviationWX\Madis;

/** netCDF data type: signed 8-bit integer. */
const NC_BYTE = 1;
/** netCDF data type: single character (raw byte). */
const NC_CHAR = 2;
/** netCDF data type: signed 16-bit big-endian integer. */
const NC_SHORT = 3;
/** netCDF data type: signed 32-bit big-endian integer. */
const NC_INT = 4;
/** netCDF data type: 32-bit big-endian IEEE float. */
const NC_FLOAT = 5;
/** netCDF data type: 64-bit big-endian IEEE float. */
const NC_DOUBLE = 6;

/**
 * Thrown when the file does not match the classic netCDF layout.
 *
 * Callers on the weather path catch this and degrade to null so one bad
 * file never breaks an airport's weather.
 */
class NetcdfFormatException extends \RuntimeException
{
}

/**
 * Reads a classic netCDF (CDF-1) file held in memory.
 */
class NetcdfClassic
{
    /** @var string Raw (uncompressed) file contents. */
    private string $bytes;

    /** @var int Number of records in the unlimited dimension. */
    private int $numRecs = 0;

    /** @var array<string, int> Dimension name to length. */
    private array $dims = [];

    /**
     * @var array<string, array{
     *   dims: array<int, string>,
     *   innerCount: int,
     *   ncType: int,
     *   vsize: int,
     *   begin: int,
     *   recordVariable: bool,
     *   attrs: array<string, mixed>
     * }>
     */
    private array $variables = [];

    /** @var array<int, string> Record variable names in header order. */
    private array $recordVariableNames = [];

    /** @var int Size in bytes of one record slab. */
    private int $recordSize = 0;

    /**
     * @param string $contents Raw netCDF bytes (must be uncompressed)
     */
    public function __construct(string $contents)
    {
        if (strlen($contents) < 8) {
            throw new NetcdfFormatException('file too short to be netCDF');
        }
        $this->bytes = $contents;
        $this->parseHeader();
    }

    /**
     * Load and parse a netCDF file from disk.
     *
     * @param string $path Path to a raw (uncompressed) netCDF file
     * @return self
     */
    public static function fromFile(string $path): self
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new NetcdfFormatException("cannot read netCDF file: {$path}");
        }

        return new self($contents);
    }

    /**
     * Number of records in the unlimited dimension.
     *
     * @return int
     */
    public function recordCount(): int
    {
        return $this->numRecs;
    }

    /**
     * Dimension name to length map.
     *
     * @return array<string, int>
     */
    public function dims(): array
    {
        return $this->dims;
    }

    /**
     * Metadata for every variable keyed by name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * True when a variable with the given name exists.
     *
     * @param string $name Variable name
     * @return bool
     */
    public function hasVariable(string $name): bool
    {
        return isset($this->variables[$name]);
    }

    /**
     * Read a scalar (zero-dimensional) variable value.
     *
     * @param string $name Variable name
     * @return mixed Scalar value
     */
    public function readScalar(string $name): mixed
    {
        $var = $this->requireVariable($name);
        if ($var['recordVariable']) {
            throw new NetcdfFormatException("variable {$name} is a record variable, not a scalar");
        }

        return $this->readOne($this->bytes, $var['begin'], $var['ncType']);
    }

    /**
     * Read one value per record for a record variable.
     *
     * Returns strings for NC_CHAR with an extra dimension, nested arrays for
     * numeric variables with extra dimensions, and scalars otherwise.
     *
     * @param string $name Variable name
     * @return array<int, mixed> Values indexed by record
     */
    public function readColumn(string $name): array
    {
        $var = $this->requireVariable($name);
        if (!$var['recordVariable']) {
            throw new NetcdfFormatException("variable {$name} is not a record variable");
        }
        $typeSize = self::typeSize($var['ncType']);
        $values = [];
        for ($record = 0; $record < $this->numRecs; $record++) {
            $offset = $var['begin'] + ($record * $this->recordSize);
            if ($var['innerCount'] === 1) {
                $values[] = $this->readOne($this->bytes, $offset, $var['ncType']);
                continue;
            }
            if ($var['ncType'] === NC_CHAR) {
                $length = $var['innerCount'];
                $values[] = rtrim(substr($this->bytes, $offset, $length), "\x00");
                continue;
            }
            $values[] = $this->readMany($offset, $var['innerCount'], $var['ncType']);
        }

        return $values;
    }

    /**
     * Read one fixed-width string per record (NC_CHAR with one extra dim).
     *
     * @param string $name Variable name
     * @return array<int, string> Trimmed strings indexed by record
     */
    public function readColumnString(string $name): array
    {
        $var = $this->requireVariable($name);
        if (!$var['recordVariable'] || $var['ncType'] !== NC_CHAR || $var['innerCount'] < 1) {
            throw new NetcdfFormatException("variable {$name} is not a per-record char column");
        }

        return $this->readColumn($name);
    }

    /**
     * Missing-value sentinels for a variable, from _FillValue or missing_value attrs.
     *
     * @param string $name Variable name
     * @return array<int, mixed>
     */
    public function missingValues(string $name): array
    {
        $var = $this->requireVariable($name);
        $sentinels = [];
        foreach (['_FillValue', 'missing_value'] as $attrName) {
            if (array_key_exists($attrName, $var['attrs'])) {
                $sentinels[] = $var['attrs'][$attrName];
            }
        }

        return $sentinels;
    }

    /**
     * True when a value equals a missing sentinel for the variable.
     *
     * @param string $name Variable name
     * @param mixed $value Value read from the file
     * @return bool
     */
    public function isMissing(string $name, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        foreach ($this->missingValues($name) as $sentinel) {
            if (is_float($sentinel) && is_float($value) && abs($sentinel - $value) < 0.5) {
                return true;
            }
            if ($sentinel === $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse the netCDF header into dims, variables, and record layout.
     */
    private function parseHeader(): void
    {
        $bytes = $this->bytes;
        $magic = substr($bytes, 0, 4);
        if ($magic !== "CDF\x01") {
            if ($magic === "CDF\x02" || $magic === "CDF\x05") {
                throw new NetcdfFormatException(
                    'netCDF CDF-2/CDF-5 (64-bit offsets) not supported; MADIS HFMETAR files are CDF-1'
                );
            }
            throw new NetcdfFormatException('not a classic netCDF file (bad magic)');
        }
        $offset = 4;
        $this->numRecs = self::readUint32($bytes, $offset);

        $dimCount = self::readListCount($bytes, $offset, 0x0A, 'dimension');
        $recordDimFound = false;
        for ($i = 0; $i < $dimCount; $i++) {
            $name = self::readString($bytes, $offset);
            $length = self::readUint32($bytes, $offset);
            $this->dims[$name] = $length;
            // Length 0 marks the record (unlimited) dimension.
            if ($length === 0) {
                $recordDimFound = true;
            }
        }

        $attrCount = self::readListCount($bytes, $offset, 0x0C, 'attribute');
        for ($i = 0; $i < $attrCount; $i++) {
            self::readAttribute($bytes, $offset);
        }

        $varCount = self::readListCount($bytes, $offset, 0x0B, 'variable');
        $this->computeRecordSizeFromVariables($bytes, $offset, $varCount, $recordDimFound);
    }

    /**
     * Read a list header: the tag (or ABSENT) followed by the element count.
     *
     * An absent list is encoded as two zero words (tag and count), so both
     * are consumed to keep the offset aligned.
     *
     * @param string $bytes File contents
     * @param int $offset Byte offset, advanced past tag and count
     * @param int $tag Expected list tag (NC_DIMENSION, NC_ATTRIBUTE, NC_VARIABLE)
     * @param string $kind List kind for error messages
     * @return int Number of elements
     */
    private static function readListCount(string $bytes, int &$offset, int $tag, string $kind): int
    {
        $listTag = self::readUint32($bytes, $offset);
        if ($listTag === 0) {
            // Absent list: the count word is present and zero.
            self::readUint32($bytes, $offset);

            return 0;
        }
        if ($listTag !== $tag) {
            throw new NetcdfFormatException("expected {$kind} list tag, got {$listTag}");
        }

        return self::readUint32($bytes, $offset);
    }

    /**
     * Parse the variable list and derive the per-record layout.
     *
     * @param string $bytes File contents
     * @param int $offset Byte offset of the variable list, advanced past it
     * @param int $varCount Number of variables
     * @param bool $recordDimFound True when the file has an unlimited dimension
     */
    private function computeRecordSizeFromVariables(string $bytes, int &$offset, int $varCount, bool $recordDimFound): void
    {
        $varStarts = [];
        for ($i = 0; $i < $varCount; $i++) {
            $name = self::readString($bytes, $offset);
            $ndims = self::readUint32($bytes, $offset);
            $dimNames = [];
            for ($d = 0; $d < $ndims; $d++) {
                $dimNames[] = array_keys($this->dims)[self::readUint32($bytes, $offset)];
            }
            $attrCount = self::readListCount($bytes, $offset, 0x0C, 'variable attribute');
            $attrs = [];
            for ($a = 0; $a < $attrCount; $a++) {
                $attr = self::readAttribute($bytes, $offset);
                $attrs[$attr['name']] = $attr['value'];
            }
            $ncType = self::readUint32($bytes, $offset);
            $vsize = self::readUint32($bytes, $offset);
            $begin = self::readUint32($bytes, $offset);

            $innerCount = 1;
            $recordVariable = false;
            foreach ($dimNames as $dimName) {
                if ($this->dims[$dimName] === 0 || $this->dims[$dimName] === 0xFFFFFFFF) {
                    $recordVariable = true;
                    continue;
                }
                $innerCount *= $this->dims[$dimName];
            }

            $this->variables[$name] = [
                'dims' => $dimNames,
                'innerCount' => $innerCount,
                'ncType' => $ncType,
                'vsize' => $vsize,
                'begin' => $begin,
                'recordVariable' => $recordVariable,
                'attrs' => $attrs,
            ];
            $varStarts[] = [$name, $begin];
        }

        if (!$recordDimFound) {
            return;
        }

        foreach ($this->variables as $name => $var) {
            if (!$var['recordVariable']) {
                continue;
            }
            $this->recordVariableNames[] = $name;
            $slabSize = $var['innerCount'] * self::typeSize($var['ncType']);
            $this->recordSize += self::roundUp4($slabSize);
        }

        // Record slabs must follow the header's begin offsets contiguously.
        $firstBegin = $this->variables[$this->recordVariableNames[0]]['begin'];
        $expected = $firstBegin;
        foreach ($this->recordVariableNames as $name) {
            $var = $this->variables[$name];
            if ($var['begin'] !== $expected) {
                throw new NetcdfFormatException(
                    "record variable {$name} begin offset {$var['begin']} does not match computed {$expected}"
                );
            }
            $expected += self::roundUp4($var['innerCount'] * self::typeSize($var['ncType']));
        }
        if ($firstBegin + ($this->numRecs * $this->recordSize) > strlen($this->bytes)) {
            throw new NetcdfFormatException('record data extends past end of file');
        }
    }

    /**
     * Read a length-prefixed NUL-terminated string from the file.
     *
     * @param string $bytes File contents
     * @param int $offset Byte offset, advanced past the string and padding
     * @return string String value without the trailing NUL
     */
    private static function readString(string $bytes, int &$offset): string
    {
        $length = self::readUint32($bytes, $offset);
        $raw = substr($bytes, $offset, $length);
        $offset += self::roundUp4($length);

        return rtrim($raw, "\x00");
    }

    /**
     * Parse a single attribute: name, type, element count, and value.
     *
     * @param string $bytes File contents
     * @param int $offset Byte offset, advanced past the attribute
     * @return array{name: string, value: mixed}
     */
    private static function readAttribute(string $bytes, int &$offset): array
    {
        $name = self::readString($bytes, $offset);
        $ncType = self::readUint32($bytes, $offset);
        $count = self::readUint32($bytes, $offset);
        $typeSize = self::typeSize($ncType);
        $valueBytes = substr($bytes, $offset, $typeSize * $count);
        $offset += self::roundUp4($typeSize * $count);

        if ($ncType === NC_CHAR) {
            return ['name' => $name, 'value' => rtrim($valueBytes, "\x00")];
        }

        $values = self::decodeValues($valueBytes, $count, $ncType);

        // Scalar attributes carry a single value, not a one-element array.
        return ['name' => $name, 'value' => $count === 1 ? $values[0] : $values];
    }

    /**
     * Decode count values of the given type from a byte block.
     *
     * @param string $block Byte block
     * @param int $count Number of values
     * @param int $ncType netCDF type constant
     * @return array<int, mixed>
     */
    private static function decodeValues(string $block, int $count, int $ncType): array
    {
        $values = [];
        $typeSize = self::typeSize($ncType);
        for ($i = 0; $i < $count; $i++) {
            $values[] = self::decodeOne($block, $i * $typeSize, $ncType);
        }

        return $values;
    }

    /**
     * Read one value of the given type at an absolute byte offset.
     *
     * @param string $bytes File contents
     * @param int $offset Absolute byte offset
     * @param int $ncType netCDF type constant
     * @return mixed
     */
    private function readOne(string $bytes, int $offset, int $ncType): mixed
    {
        return self::decodeOne($bytes, $offset, $ncType);
    }

    /**
     * Read many values of the given type starting at an absolute offset.
     *
     * @param int $offset Absolute byte offset
     * @param int $count Number of values
     * @param int $ncType netCDF type constant
     * @return array<int, mixed>
     */
    private function readMany(int $offset, int $count, int $ncType): array
    {
        $typeSize = self::typeSize($ncType);

        return self::decodeValues(substr($this->bytes, $offset, $typeSize * $count), $count, $ncType);
    }

    /**
     * Decode a single value from a byte block at a relative offset.
     *
     * @param string $block Byte block
     * @param int $offset Offset within the block
     * @param int $ncType netCDF type constant
     * @return mixed
     */
    private static function decodeOne(string $block, int $offset, int $ncType): mixed
    {
        switch ($ncType) {
            case NC_BYTE:
                return unpack('c', $block[$offset])[1];
            case NC_CHAR:
                return $block[$offset];
            case NC_SHORT:
                $value = unpack('n', substr($block, $offset, 2))[1];

                return $value >= 0x8000 ? $value - 0x10000 : $value;
            case NC_INT:
                $value = unpack('N', substr($block, $offset, 4))[1];

                return $value >= 0x80000000 ? $value - 0x100000000 : $value;
            case NC_FLOAT:
                return unpack('G', substr($block, $offset, 4))[1];
            case NC_DOUBLE:
                return unpack('E', substr($block, $offset, 8))[1];
            default:
                throw new NetcdfFormatException("unsupported netCDF type: {$ncType}");
        }
    }

    /**
     * Read a big-endian uint32 and advance the offset.
     *
     * @param string $bytes File contents
     * @param int $offset Byte offset, advanced by 4
     * @return int
     */
    private static function readUint32(string $bytes, int &$offset): int
    {
        $value = unpack('N', substr($bytes, $offset, 4))[1];
        $offset += 4;

        return $value;
    }

    /**
     * Bytes occupied by one element of the given netCDF type.
     *
     * @param int $ncType netCDF type constant
     * @return int
     */
    private static function typeSize(int $ncType): int
    {
        return match ($ncType) {
            NC_BYTE, NC_CHAR => 1,
            NC_SHORT => 2,
            NC_INT, NC_FLOAT => 4,
            NC_DOUBLE => 8,
            default => throw new NetcdfFormatException("unsupported netCDF type: {$ncType}"),
        };
    }

    /**
     * Round a byte count up to a multiple of 4.
     *
     * @param int $size Byte count
     * @return int
     */
    private static function roundUp4(int $size): int
    {
        return ($size + 3) & ~3;
    }

    /**
     * Fetch a variable's metadata or throw if absent.
     *
     * @param string $name Variable name
     * @return array<string, mixed>
     */
    private function requireVariable(string $name): array
    {
        if (!isset($this->variables[$name])) {
            throw new NetcdfFormatException("variable not found: {$name}");
        }

        return $this->variables[$name];
    }
}
