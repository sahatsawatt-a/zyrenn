import { xsrfToken } from '@/lib/utils';
import { pick } from '@/routes/drive';
import { store } from '@/routes/drive/files';

// A Drive file as the server describes it (DriveFile::card)
export type DriveFile = {
    ref_id: string;
    name: string;
    kind: 'image' | 'pdf' | 'audio' | 'video' | 'doc' | 'archive' | 'other';
    mime: string | null;
    size: number;
    is_image: boolean;
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

export const isImageFile = (file: File) => IMAGE_MIMES.includes(file.type);

const jsonHeaders = () => ({
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-XSRF-TOKEN': xsrfToken(),
});

/**
 * Upload one file into the root of the user's Drive.
 */
export async function uploadToDrive(file: File): Promise<DriveFile> {
    const body = new FormData();
    body.append('files[]', file);

    const response = await fetch(store.url(), {
        method: 'POST',
        headers: jsonHeaders(),
        body,
    });

    if (!response.ok) {
        const message =
            response.status === 413 || response.status === 422
                ? `${file.name} is too large or not allowed.`
                : `Couldn’t upload ${file.name}.`;

        throw new Error(message);
    }

    return (await response.json()).files[0];
}

/**
 * The user's Drive images, newest first, optionally filtered by name.
 */
export async function listDriveImages(query = ''): Promise<DriveFile[]> {
    const response = await fetch(
        pick.url({ query: query ? { q: query } : {} }),
        { headers: jsonHeaders() },
    );

    if (!response.ok) {
        throw new Error('Couldn’t load your Drive.');
    }

    return (await response.json()).files;
}
