<?php

namespace App\Models;

use App\Models\Board\Board;
use App\Models\Board\BoardFolder;
use App\Models\Drive\DriveFile;
use App\Models\Drive\DriveFolder;
use App\Models\Map\Place;
use App\Models\Map\PlaceList;
use App\Models\Map\Trip;
use App\Models\Map\TripFolder;
use App\Models\Note\Note;
use App\Models\Note\NoteFolder;
use App\Models\Table\Table;
use App\Models\Table\TableFolder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Whoever notes, boards, tables, trips, saved places and Drive files belong to: a user, for their
 * own, or a project, for what its members share. Things go with their owner.
 *
 * @see Concerns\OwnsContent
 */
interface Owner
{
    /**
     * The column a thing names its owner in: "user_id" or "project_id".
     */
    public function ownerColumn(): string;

    /**
     * Where the owner's Drive keeps its bytes, on DriveFile::DISK.
     */
    public function driveDirectory(): string;

    /**
     * @return mixed
     */
    public function getKey();

    /**
     * @return HasMany<Note, covariant Model&Owner>
     */
    public function notes(): HasMany;

    /**
     * @return HasMany<NoteFolder, covariant Model&Owner>
     */
    public function noteFolders(): HasMany;

    /**
     * @return HasMany<Board, covariant Model&Owner>
     */
    public function boards(): HasMany;

    /**
     * @return HasMany<BoardFolder, covariant Model&Owner>
     */
    public function boardFolders(): HasMany;

    /**
     * @return HasMany<Table, covariant Model&Owner>
     */
    public function tables(): HasMany;

    /**
     * @return HasMany<TableFolder, covariant Model&Owner>
     */
    public function tableFolders(): HasMany;

    /**
     * @return HasMany<Trip, covariant Model&Owner>
     */
    public function trips(): HasMany;

    /**
     * @return HasMany<TripFolder, covariant Model&Owner>
     */
    public function tripFolders(): HasMany;

    /**
     * @return HasMany<PlaceList, covariant Model&Owner>
     */
    public function placeLists(): HasMany;

    /**
     * @return HasMany<Place, covariant Model&Owner>
     */
    public function places(): HasMany;

    /**
     * @return HasMany<DriveFile, covariant Model&Owner>
     */
    public function driveFiles(): HasMany;

    /**
     * @return HasMany<DriveFolder, covariant Model&Owner>
     */
    public function driveFolders(): HasMany;
}
