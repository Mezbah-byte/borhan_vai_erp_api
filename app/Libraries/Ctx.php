<?php

namespace App\Libraries;

/** Request-scoped auth context, filled by ApiAuth filter. */
class Ctx
{
    public static ?array $user = null;
    /** business_un_id => permission list */
    public static array $access = [];
    public static ?string $biz = null;
    /** Permissions already checked this request; bizIds() and useBiz() honour them per business. */
    public static array $needed = [];
    /** business_un_id => enabled modules of its business type (null = no restriction) */
    public static array $modules = [];
    /** Permission prefixes that are switchable modules per business type. */
    public const MODULES = ['visa', 'project', 'finance', 'expense', 'hr', 'party', 'report'];

    /** Businesses the user can reach that grant every permission checked so far. */
    public static function bizIds(): array
    {
        return array_values(array_filter(array_keys(self::$access), static fn ($b) => self::canAll($b)));
    }

    public static function canAll(string $biz): bool
    {
        foreach (self::$needed as $p) {
            if (! self::can($p, $biz)) {
                return false;
            }
        }

        return true;
    }

    public static function perms(?string $biz = null): array
    {
        $biz ??= self::$biz;
        if ($biz) {
            return self::$access[$biz] ?? [];
        }

        return array_values(array_unique(array_merge([], ...array_values(self::$access))));
    }

    public static function can(string $perm, ?string $biz = null): bool
    {
        $biz ??= self::$biz;
        if ($biz === null) {
            foreach (array_keys(self::$access) as $b) {
                if (self::can($perm, $b)) {
                    return true;
                }
            }

            return false;
        }
        [$mod] = explode('.', $perm);
        // A role's "*" never unlocks a module the business type doesn't have (e.g. visa on a farm).
        $mods = self::$modules[$biz] ?? null;
        if ($mods !== null && in_array($mod, self::MODULES, true) && ! in_array($mod, $mods, true)) {
            return false;
        }
        $p = self::$access[$biz] ?? [];

        return in_array('*', $p, true) || in_array($perm, $p, true) || in_array($mod . '.*', $p, true);
    }
}
