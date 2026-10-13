<?php

namespace App\Http\Controllers;

use App\Support\Connections\Withdrawal;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What anyone may read without an account: how the app treats what it is
 * given, on what terms it is used, and how to have what it holds deleted.
 */
class LegalController extends Controller
{
    /** The day the three pages were last changed. */
    private const UPDATED = '2026-10-13';

    public function privacy(): Response
    {
        return Inertia::render('legal/Privacy', self::shared());
    }

    public function terms(): Response
    {
        return Inertia::render('legal/Terms', self::shared());
    }

    /**
     * How to have what the app holds deleted -- and, by the receipt a
     * deletion asked for through Meta was given, that it was.
     */
    public function deletion(?string $receipt = null): Response
    {
        $done = $receipt === null ? null : Withdrawal::receiptSays($receipt);

        abort_if($receipt !== null && $done === null, 404);

        return Inertia::render('legal/DataDeletion', [
            ...self::shared(),
            'done' => $done === null ? null : ['receipt' => $receipt, 'at' => $done['at']->toIso8601String(), 'removed' => $done['removed']],
        ]);
    }

    /**
     * @return array{contact: string|null, updated: string}
     */
    private static function shared(): array
    {
        return ['contact' => config('app.contact') ?: null, 'updated' => self::UPDATED];
    }
}
