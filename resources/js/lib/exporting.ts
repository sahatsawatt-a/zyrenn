import { toast } from 'vue-sonner';
import { uploadToDrive } from './drive';

/**
 * A file the server makes when asked -- a note's or a board's PDF, a picture
 * of a board -- fetched and named for keeping. A warning the server sends
 * along (a picture that didn't load, say) is shown, not thrown.
 */
export async function fetchExport(
    url: string,
    name: string,
    type: string,
    failure: string,
): Promise<File> {
    const response = await fetch(url, {
        headers: {
            Accept: `${type}, application/json`,
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        const body = response.headers
            .get('Content-Type')
            ?.includes('application/json')
            ? await response.json()
            : null;

        throw new Error(
            body?.message ??
                (response.status === 429
                    ? 'Too many exports at once. Try again in a minute.'
                    : failure),
        );
    }

    const warning = response.headers.get('X-Pdf-Warning');

    if (warning) {
        toast.warning(warning);
    }

    // A slash would make the Drive keep only what follows it as the file's name
    return new File([await response.blob()], name.replace(/[/\\]/g, '-'), {
        type,
    });
}

/** Hands a file over: downloaded, or saved to the Drive with a way to open it. */
export async function deliver(
    file: File,
    to: 'download' | 'drive',
): Promise<void> {
    if (to === 'download') {
        const url = URL.createObjectURL(file);
        const link = document.createElement('a');
        link.href = url;
        link.download = file.name;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);

        return;
    }

    const stored = await uploadToDrive(file);
    toast.success(`Saved “${stored.name}” to your Drive`, {
        action: {
            label: 'Open',
            onClick: () => window.open(stored.url, '_blank'),
        },
    });
}
