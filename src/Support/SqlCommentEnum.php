<?php

declare(strict_types=1);

namespace Goletter\Server\Support;

/**
 * SQL 列注释枚举解析与 Model 常量块渲染。
 *
 * 格式：中文名:值|标识=标签[,...]；无冒号/无合规项 → 不生成常量。
 * 例：状态:1|online=待处理,2|offline=下线
 */
final class SqlCommentEnum
{
    /** 可承载注释枚举的列基类型（排除 json/set/enum/bit/浮点等）。 */
    private const array INT_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'];

    private const array STRING_TYPES = ['varchar', 'char'];

    /**
     * attributes/descriptions 用：冒号后像枚举项时只取中文名；否则整段保留。
     */
    public static function label(string $comment, string $fallback = ''): string
    {
        $comment = trim($comment);
        if ($comment === '') {
            return $fallback;
        }
        $pos = strpos($comment, ':');
        if ($pos === false) {
            return $comment;
        }
        $name = trim(substr($comment, 0, $pos));
        $itemsPart = trim(substr($comment, $pos + 1));
        if ($name === '' || $itemsPart === '' || ! self::looksLikeEnumItems($itemsPart)) {
            return $comment;
        }

        return $name;
    }

    /**
     * @return array{label: string, items: list<array{value: int|string, ident: string, tag: string}>}|null
     */
    public static function tryParse(string $comment, string $baseType): ?array
    {
        $comment = trim($comment);
        $baseType = strtolower($baseType);
        $valueKind = self::valueKind($baseType);
        if ($valueKind === null || $comment === '') {
            return null;
        }

        $pos = strpos($comment, ':');
        if ($pos === false) {
            return null;
        }

        $label = trim(substr($comment, 0, $pos));
        $itemsPart = trim(substr($comment, $pos + 1));
        if ($label === '' || $itemsPart === '') {
            return null;
        }

        $chunks = explode(',', $itemsPart);
        $items = [];
        $seenValues = [];
        $seenIdents = [];

        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                return null;
            }
            if (! preg_match('/^([^|=]+)\|([a-zA-Z][a-zA-Z0-9_]*)=([^,=:|]+)$/u', $chunk, $matches)) {
                return null;
            }

            $rawValue = trim($matches[1]);
            $ident = self::normalizeIdent($matches[2]);
            $tag = trim($matches[3]);
            if ($ident === '' || $tag === '') {
                return null;
            }

            $value = self::coerceValue($rawValue, $valueKind);
            if ($value === null) {
                return null;
            }

            $valueKey = is_int($value) ? (string) $value : 's:' . $value;
            if (isset($seenValues[$valueKey]) || isset($seenIdents[$ident])) {
                return null;
            }
            $seenValues[$valueKey] = true;
            $seenIdents[$ident] = true;

            $items[] = ['value' => $value, 'ident' => $ident, 'tag' => $tag];
        }

        if ($items === []) {
            return null;
        }

        return ['label' => $label, 'items' => $items];
    }

    /**
     * @param list<array{value: int|string, ident: string, tag: string}> $items
     * @param string $comment 原始列注释，只写在 START 下一行，避免重复
     */
    public static function renderConstants(string $field, array $items, string $comment): string
    {
        $prefix = strtoupper($field);
        $fieldSnake = self::normalizeIdent($field);
        $safeComment = str_replace(["\r", "\n"], ' ', trim($comment));
        $valueType = is_int($items[0]['value']) ? 'int' : 'string';
        $lines = [
            '',
            sprintf('    // === %s START（由生成器生成，请勿手改）===', $prefix),
            sprintf('    // %s', $safeComment),
        ];

        $constNames = [];
        foreach ($items as $item) {
            $constNames[] = $prefix . '_' . strtoupper(self::identSuffix($item['ident'], $fieldSnake));
        }

        foreach ($items as $i => $item) {
            $lines[] = sprintf('    public const %s %s = %s;', $valueType, $constNames[$i], self::phpLiteral($item['value']));
        }

        $lines[] = '';
        $lines[] = sprintf('    public const array %s_LABELS = [', $prefix);
        foreach ($items as $i => $item) {
            $lines[] = sprintf("        self::%s => '%s',", $constNames[$i], addslashes($item['tag']));
        }
        $lines[] = '    ];';

        $lines[] = '';
        $lines[] = sprintf('    public const array %s_ALL = [', $prefix);
        foreach ($constNames as $const) {
            $lines[] = sprintf('        self::%s,', $const);
        }
        $lines[] = '    ];';
        $lines[] = sprintf('    // === %s END ===', $prefix);
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** Blueprint / SQL 类型归一为 SqlCommentEnum 可识别的基类型。 */
    public static function normalizeBaseType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = (string) (preg_split('/[ (]/', $type)[0] ?? $type);

        return match ($type) {
            'tinyinteger', 'unsignedtinyinteger' => 'tinyint',
            'smallinteger', 'unsignedsmallinteger' => 'smallint',
            'mediuminteger', 'unsignedmediuminteger' => 'mediumint',
            'integer', 'unsignedinteger' => 'int',
            'biginteger', 'unsignedbiginteger', 'bigincrements', 'increments' => 'bigint',
            'string' => 'varchar',
            default => $type,
        };
    }

    private static function looksLikeEnumItems(string $itemsPart): bool
    {
        $chunks = explode(',', $itemsPart);
        if ($chunks === []) {
            return false;
        }
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || ! preg_match('/^([^|=]+)\|([a-zA-Z][a-zA-Z0-9_]*)=([^,=:|]+)$/u', $chunk)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return 'int'|'string'|null
     */
    private static function valueKind(string $baseType): ?string
    {
        if (in_array($baseType, self::INT_TYPES, true)) {
            return 'int';
        }
        if (in_array($baseType, self::STRING_TYPES, true)) {
            return 'string';
        }

        return null;
    }

    private static function coerceValue(string $raw, string $valueKind): int|string|null
    {
        if ($valueKind === 'int') {
            if (! preg_match('/^-?\d+$/', $raw)) {
                return null;
            }

            return (int) $raw;
        }

        if (! preg_match('/^[A-Za-z0-9_.-]+$/', $raw)) {
            return null;
        }

        return $raw;
    }

    private static function phpLiteral(int|string $value): string
    {
        return is_int($value) ? (string) $value : "'" . addslashes($value) . "'";
    }

    /** online / stepZero / step_zero → online / step_zero / step_zero */
    private static function normalizeIdent(string $ident): string
    {
        $ident = trim($ident);
        if ($ident === '') {
            return '';
        }
        // camelCase → snake_case
        $snake = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $ident));
        $snake = (string) preg_replace('/_+/', '_', $snake);

        return trim($snake, '_');
    }

    /**
     * 去掉与字段名重复的前缀，避免 step + stepZero → STEP_STEP_ZERO。
     * stepZero + field step → zero；online + field status → online。
     */
    private static function identSuffix(string $identSnake, string $fieldSnake): string
    {
        if ($fieldSnake !== '' && str_starts_with($identSnake, $fieldSnake . '_')) {
            $suffix = substr($identSnake, strlen($fieldSnake) + 1);

            return $suffix !== '' ? $suffix : $identSnake;
        }

        return $identSnake;
    }
}
