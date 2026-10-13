<?php

namespace App\Support;

use App\Models\Folder;
use App\Models\Owner;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The order each person last put each folder in.
 *
 * A folder is sorted by whoever is looking at it, and stays that way for
 * them: open it again -- from a link that says nothing of sorting, on another
 * machine -- and it is in the order they left it. Each folder has its own,
 * and so has the top level of each kind of thing, a person's own apart from
 * each project's. In a project it is still each member's, not the project's.
 */
class ListSorts
{
    /**
     * The order to show a list in: the one just asked for, which is then
     * remembered, or failing that the one remembered, or the usual one.
     *
     * @param  class-string<Folder>  $folders  the kind of folder the list is of
     * @param  list<string>  $allowed  the orders this list can be put in
     */
    public static function settle(?User $user, string $folders, Owner $owner, ?Folder $folder, ?string $asked, array $allowed, string $usual): string
    {
        if ($user === null) {
            return $asked ?? $usual;
        }

        $list = self::name($folders, $owner, $folder);
        $kept = DB::table('list_sorts')->where('user_id', $user->id)->where('list', $list)->value('sort');

        if ($asked === null) {
            // An order since taken away is not one to go back to
            return in_array($kept, $allowed, true) ? $kept : $usual;
        }

        if ($asked !== $kept) {
            DB::table('list_sorts')->updateOrInsert(
                ['user_id' => $user->id, 'list' => $list],
                ['sort' => $asked, 'updated_at' => now(), ...($kept === null ? ['created_at' => now()] : [])],
            );
        }

        return $asked;
    }

    /**
     * What a list is kept under: the folder itself, or the top level of
     * that kind of thing for the owner.
     *
     * @param  class-string<Folder>  $folders
     */
    private static function name(string $folders, Owner $owner, ?Folder $folder): string
    {
        $kind = class_basename($folders);

        if ($folder !== null) {
            return "{$kind}:{$folder->ref_id}";
        }

        return $owner instanceof Project ? "{$kind}:project:{$owner->ref_id}" : "{$kind}:own";
    }
}
