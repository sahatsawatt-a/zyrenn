<?php

namespace App\Models\Concerns;

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
use App\Models\Owner;
use App\Models\Table\Table;
use App\Models\Table\TableFolder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An Owner's notes, boards, tables, trips, saved places and Drive, reached through its own column
 * -- "user_id" for a User, "project_id" for a Project -- so each only ever
 * sees its own.
 *
 * @phpstan-require-implements Owner
 */
trait OwnsContent
{
    public function ownerColumn(): string
    {
        return $this->getForeignKey();
    }

    /**
     * @return HasMany<Note, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    /**
     * @return HasMany<NoteFolder, $this>
     */
    public function noteFolders(): HasMany
    {
        return $this->hasMany(NoteFolder::class);
    }

    /**
     * @return HasMany<Board, $this>
     */
    public function boards(): HasMany
    {
        return $this->hasMany(Board::class);
    }

    /**
     * @return HasMany<BoardFolder, $this>
     */
    public function boardFolders(): HasMany
    {
        return $this->hasMany(BoardFolder::class);
    }

    /**
     * @return HasMany<Table, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(Table::class);
    }

    /**
     * @return HasMany<TableFolder, $this>
     */
    public function tableFolders(): HasMany
    {
        return $this->hasMany(TableFolder::class);
    }

    /**
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * @return HasMany<TripFolder, $this>
     */
    public function tripFolders(): HasMany
    {
        return $this->hasMany(TripFolder::class);
    }

    /**
     * @return HasMany<PlaceList, $this>
     */
    public function placeLists(): HasMany
    {
        return $this->hasMany(PlaceList::class);
    }

    /**
     * @return HasMany<Place, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(Place::class);
    }

    /**
     * @return HasMany<DriveFile, $this>
     */
    public function driveFiles(): HasMany
    {
        return $this->hasMany(DriveFile::class);
    }

    /**
     * @return HasMany<DriveFolder, $this>
     */
    public function driveFolders(): HasMany
    {
        return $this->hasMany(DriveFolder::class);
    }
}
