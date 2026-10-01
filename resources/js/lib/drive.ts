import { owned } from '@/lib/projects';
import { xsrfToken } from '@/lib/utils';
import { pick } from '@/routes/drive';
import { store } from '@/routes/drive/files';
import { pick as projectPick } from '@/routes/projects/drive';
import { store as projectStore } from '@/routes/projects/drive/files';

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
