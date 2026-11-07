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
            if (! preg_match('/^([^|=]+)\|([a-z][a-z0-9_]*)=([^,=:|]+)$/u', $chunk, $matches)) {
                return null;
            }

            $rawValue = trim($matches[1]);
            $ident = $matches[2];
            $tag = trim($matches[3]);
            if ($tag === '') {
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
     * @param string $comment 原始列注释，整段进 @var 供 PhpStorm 悬停
     */
    public static function renderConstants(string $field, array $items, string $comment): string
    {
        $prefix = strtoupper($field);
        $safeComment = str_replace('*/', '* /', trim($comment));
        $valueType = is_int($items[0]['value']) ? 'int' : 'string';
        $lines = [
            '',
            sprintf('    // === %s START（由生成器生成，请勿手改）===', $prefix),
            sprintf('    /** @var %s %s */', $valueType, $safeComment),
        ];

        foreach ($items as $item) {
            $const = $prefix . '_' . strtoupper($item['ident']);
            $lines[] = sprintf('    public const %s %s = %s;', $valueType, $const, self::phpLiteral($item['value']));
        }

        $lines[] = '';
        $lines[] = sprintf('    /** @var array %s */', $safeComment);
        $lines[] = sprintf('    public const array %s_LABELS = [', $prefix);
        foreach ($items as $item) {
            $const = $prefix . '_' . strtoupper($item['ident']);
            $lines[] = sprintf("        self::%s => '%s',", $const, addslashes($item['tag']));
        }
        $lines[] = '    ];';

        $lines[] = '';
        $lines[] = sprintf('    /** @var array %s */', $safeComment);
        $lines[] = sprintf('    public const array %s_ALL = [', $prefix);
        foreach ($items as $item) {
            $const = $prefix . '_' . strtoupper($item['ident']);
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
            if ($chunk === '' || ! preg_match('/^([^|=]+)\|([a-z][a-z0-9_]*)=([^,=:|]+)$/u', $chunk)) {
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
}
