import { usePage } from '@inertiajs/vue3';
import type {
    RouteDefinition,
    RouteFormDefinition,
    RouteQueryOptions,
} from '@/wayfinder';

export type ProjectRole = 'owner' | 'editor' | 'viewer';

// The project a page is in (HandleInertiaRequests::currentProject)
export type CurrentProject = {
    ref_id: string;
    name: string;
    role: ProjectRole | null;
};

export type ProjectSummary = { ref_id: string; name: string };

/**
 * The project the page is in, or null on the user's own things.
 */
export const currentProject = (): CurrentProject | null =>
    usePage().props.project ?? null;

/**
 * Whether the user can make and change things where the page is: always in
 * their own, and as a project's owner or editor. Viewers only look.
 */
export const canChange = (): boolean => {
    const project = currentProject();

    return !project || project.role === 'owner' || project.role === 'editor';
};

type Method = 'get' | 'post';

// e.g. notes.index: "/notes"
export type OwnRoute<M extends Method> = ((
    options?: RouteQueryOptions,
) => RouteDefinition<M>) & {
    url: (options?: RouteQueryOptions) => string;
    form: (options?: RouteQueryOptions) => RouteFormDefinition<M>;
};

// e.g. projects.notes.index: "/p/{project}/notes"
export type ProjectRoute<M extends Method> = ((
    project: string,
    options?: RouteQueryOptions,
) => RouteDefinition<M>) & {
    url: (project: string, options?: RouteQueryOptions) => string;
    form: (
        project: string,
        options?: RouteQueryOptions,
    ) => RouteFormDefinition<M>;
};

/**
 * A route registered with Route::owned(), for wherever the page is: the
 * user's own as it is, or a project's with the project filled in. A page
 * lists, makes and picks in the right place without knowing which it is.
 *
 * The project is looked up each time a URL is made, not once: Inertia keeps
 * a page alive going from your own notes to a project's, and a route that
 * remembered where it began would keep sending things back there.
 */
export function owned<M extends Method>(
    own: OwnRoute<M>,
    inProject: ProjectRoute<M>,
): OwnRoute<M> {
    const ref = () => currentProject()?.ref_id;

    return Object.assign(
        (options?: RouteQueryOptions) => {
            const project = ref();

            return project ? inProject(project, options) : own(options);
        },
        {
            url: (options?: RouteQueryOptions) => {
                const project = ref();

                return project
                    ? inProject.url(project, options)
                    : own.url(options);
            },
            form: (options?: RouteQueryOptions) => {
                const project = ref();

                return project
                    ? inProject.form(project, options)
                    : own.form(options);
            },
        },
    );
}
