<?php

namespace App\Support;

/**
 * Reader for the generic `leave_rules` rows a resolved policy carries.
 *
 * LeavePolicyResolver::resolveWithRules() hands back a list of
 * {rule_key, operator, value, condition, priority, ...} entries, ordered by
 * priority. Phase 5 reads payment behaviour (is payment enabled, may statutory
 * leave be encashed, may days be purchased, what divisor turns a salary into a
 * day rate, does an outstanding loan block payment) from those rows instead of
 * hard-coding it, so a deployment can change the policy without a migration.
 *
 * The first row matching a key wins, which is the same precedence the resolver
 * already applied (priority desc, id asc). A missing key falls back to the
 * caller's default, never to a silent zero.
 */
class PolicyRules
{
    /**
     * @param  array<int,array<string,mixed>>  $rules
     */
    public static function get(array $rules, string $key, mixed $default = null): mixed
    {
        foreach ($rules as $rule) {
            if (($rule['rule_key'] ?? null) !== $key) {
                continue;
            }

            $value = $rule['value'] ?? null;

            // value_json is free-form. A wrapped shape such as
            // {"value": 30} or {"enabled": true} is unwrapped so callers get the
            // scalar they expect rather than an array they have to dig through.
            if (is_array($value) && count($value) === 1) {
                return array_values($value)[0];
            }

            return $value;
        }

        return $default;
    }

    /**
     * @param  array<int,array<string,mixed>>  $rules
     */
    public static function enabled(array $rules, string $key, bool $default): bool
    {
        $value = self::get($rules, $key, null);

        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return (bool) ($value['enabled'] ?? $value['value'] ?? true);
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rules
     */
    public static function number(array $rules, string $key, ?float $default = null): ?float
    {
        $value = self::get($rules, $key, null);

        if ($value === null || ! is_numeric($value)) {
            return $default;
        }

        return (float) $value;
    }
}
