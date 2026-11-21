<?php

declare(strict_types=1);

namespace LibreNMS\Plugins\AkvoradoFlow\Services;

use App\Models\User;
use App\Models\UserPref;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SavedInvestigationViews
{
    private const MAX_VIEWS = 20;

    public function forDevice(User $user, int $deviceId): array
    {
        $result = [];
        foreach (array_reverse($this->stored($user, $deviceId), true) as $id => $view) {
            if (! is_array($view) || ! is_string($view['name'] ?? null) || ! is_array($view['query'] ?? null)) {
                continue;
            }
            $bounds = null;
            $expired = false;
            $query = $view['query'];
            try {
                $start = $query['start'] ?? null;
                $end = $query['end'] ?? null;
                $bounds = FlowQuery::bounds(is_int($start) ? (string)$start : $start, is_int($end) ? (string)$end : $end);
            } catch (InvalidArgumentException) {
                $expired = true;
            }
            try {
                $result[$id] = ['name' => $view['name'], 'query' => InvestigationView::query($query, $bounds), 'expired' => $expired];
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $result;
    }

    public function save(User $user, int $deviceId, string $name, array $query): void
    {
        DB::transaction(function () use ($user, $deviceId, $name, $query): void {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $views = $this->stored($locked, $deviceId);
            if (count($views) >= self::MAX_VIEWS) {
                throw new DomainException('Remove a saved view before adding another. Each device allows 20 saved views per user.');
            }
            foreach ($views as $view) {
                if (is_string($view['name'] ?? null) && mb_strtolower($view['name']) === mb_strtolower($name)) {
                    throw new DomainException('You already have a saved view with that name on this device.');
                }
            }
            $views[bin2hex(random_bytes(12))] = ['name' => $name, 'query' => $query];
            UserPref::setPref($locked, $this->key($deviceId), $views);
        });
    }

    public function remove(User $user, int $deviceId, string $id): void
    {
        DB::transaction(function () use ($user, $deviceId, $id): void {
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $views = $this->stored($locked, $deviceId);
            if (! isset($views[$id])) {
                return;
            }
            unset($views[$id]);
            if ($views === []) {
                UserPref::forgetPref($locked, $this->key($deviceId));
            } else {
                UserPref::setPref($locked, $this->key($deviceId), $views);
            }
        });
    }

    private function stored(User $user, int $deviceId): array
    {
        $value = UserPref::getPref($user, $this->key($deviceId));

        return is_array($value) ? $value : [];
    }

    private function key(int $deviceId): string
    {
        return 'akvorado_saved_views_v1_'.$deviceId;
    }
}
