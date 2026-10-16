import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { xsrfToken } from '@/lib/utils';
import { enable as enableRoute } from '@/routes/workflows';

// Switched on, a workflow's own triggers start runs by themselves, as it
// was published

export function useWorkflowSwitch(refId: string, first: boolean) {
    const enabled = ref(first);
    const switching = ref(false);

    async function switchOn(on: boolean) {
        switching.value = true;

        try {
            const response = await fetch(enableRoute(refId).url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ enabled: on }),
            });
            const body = await response.json().catch(() => ({}));

            if (!response.ok) {
                toast.error(body.message ?? 'It could not be switched.');

                return;
            }

            enabled.value = body.enabled;
            toast.success(
                body.enabled
                    ? 'Switched on: its triggers start runs by themselves.'
                    : 'Switched off.',
            );
        } finally {
            switching.value = false;
        }
    }

    return { enabled, switching, switchOn };
}
