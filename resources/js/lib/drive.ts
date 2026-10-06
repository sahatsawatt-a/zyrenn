import { owned } from '@/lib/projects';
import { xsrfToken } from '@/lib/utils';
import { pick } from '@/routes/drive';
import { original, store } from '@/routes/drive/files';
import { store as photoEditStore } from '@/routes/drive/photo-edits';
import { pick as projectPick } from '@/routes/projects/drive';
import { store as projectStore } from '@/routes/projects/drive/files';
import { store as projectPhotoEditStore } from '@/routes/projects/drive/photo-edits';
import type { PhotoEdit } from './photo';

// A Drive file as the server describes it (DriveFile::card)
export type DriveFile = {
    ref_id: string;
    name: string;
    kind: 'image' | 'pdf' | 'audio' | 'video' | 'doc' | 'archive' | 'other';
    mime: string | null;
    size: number;
    is_image: boolean;
    is_video: boolean;
    url: string;
    created_at: string;
};

// Kept in sync with DriveFile::IMAGE_MIMES
export const IMAGE_MIMES = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'image/avif',
    'image/svg+xml',
];

// Kept in sync with DriveFile::VIDEO_MIMES
export const VIDEO_MIMES = [
    'video/mp4',
    'video/x-m4v',
    'video/quicktime',
    'video/webm',
    'video/ogg',
];

export const isImageFile = (file: File) => IMAGE_MIMES.includes(file.type);

export const isVideoFile = (file: File) => VIDEO_MIMES.includes(file.type);

/** What can be shown in a note or on a board: a picture, or a video that plays there. */
export type MediaKind = 'image' | 'video';

const jsonHeaders = () => ({
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-XSRF-TOKEN': xsrfToken(),
});

/**
 * Upload one file into the root of the Drive of wherever the page is: the
 * project's, so everyone in it can see the picture, or the user's own.
 * `onProgress` hears how much of it has gone, from 0 to 1 -- a video can take
 * a while, and fetch() can't say.
 */
export function uploadToDrive(
    file: File,
    onProgress?: (fraction: number) => void,
): Promise<DriveFile> {
    const body = new FormData();
    body.append('files[]', file);

    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        request.open('POST', owned(store, projectStore).url());

        for (const [name, value] of Object.entries(jsonHeaders())) {
            request.setRequestHeader(name, value);
        }

        request.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                onProgress?.(event.loaded / event.total);
            }
        };

        request.onload = () => {
            if (request.status >= 200 && request.status < 300) {
                resolve(JSON.parse(request.responseText).files[0]);

                return;
            }

            reject(
                new Error(
                    request.status === 413 || request.status === 422
                        ? `${file.name} is too large or not allowed.`
                        : `Couldn’t upload ${file.name}.`,
                ),
            );
        };
        request.onerror = () =>
            reject(new Error(`Couldn’t upload ${file.name}.`));

        request.send(body);
    });
}

/**
 * The Drive images (or videos) of wherever the page is, newest first,
 * optionally filtered by name.
 */
export async function listDriveMedia(
    kind: MediaKind,
    query = '',
): Promise<DriveFile[]> {
    const response = await fetch(
        owned(pick, projectPick).url({
            query: { kind, ...(query ? { q: query } : {}) },
        }),
        { headers: jsonHeaders() },
    );

    if (!response.ok) {
        throw new Error('Couldn’t load your Drive.');
    }

    return (await response.json()).files;
}

/** A Drive file's ref_id, when a picture's address is one of ours. */
export const driveRefOf = (src: string): string | null =>
    src.match(
        /^(?:https?:\/\/[^/]+)?\/drive\/files\/([A-Za-z0-9]+)(?:[/?#]|$)/,
    )?.[1] ?? null;

/**
 * Where to start editing a Drive picture: the original it was made from in
 * the photo editor, and the edit that made it -- when there is one.
 */
export async function photoOriginal(ref: string): Promise<{
    file: DriveFile;
    source: DriveFile | null;
    edit: PhotoEdit | null;
}> {
    const response = await fetch(original.url(ref), {
        headers: jsonHeaders(),
    });

    if (!response.ok) {
        throw new Error('Couldn’t open that picture.');
    }

    return response.json();
}

/**
 * Keep a picture from the photo editor in the Drive of wherever the page is,
 * as made from `source` (a Drive picture's ref_id) by `edit`.
 */
export async function savePhotoEdit(
    blob: Blob,
    source: string | null,
    edit: PhotoEdit,
): Promise<DriveFile> {
    const body = new FormData();
    const ext =
        blob.type.split('/')[1] === 'jpeg' ? 'jpg' : blob.type.split('/')[1];
    body.append('file', blob, `photo.${ext}`);

    if (source) {
        body.append('source', source);

        for (const [key, value] of Object.entries(edit)) {
            if (key === 'crop') {
                for (const [side, fraction] of Object.entries(edit.crop)) {
                    body.append(`edit[crop][${side}]`, String(fraction));
                }
            } else {
                body.append(
                    `edit[${key}]`,
                    String(typeof value === 'boolean' ? Number(value) : value),
                );
            }
        }
    }

    const response = await fetch(
        owned(photoEditStore, projectPhotoEditStore).url(),
        { method: 'POST', headers: jsonHeaders(), body },
    );

    if (!response.ok) {
        throw new Error(
            response.status === 403
                ? 'You can’t add pictures here.'
                : 'Couldn’t save the picture.',
        );
    }

    return (await response.json()).file;
}
