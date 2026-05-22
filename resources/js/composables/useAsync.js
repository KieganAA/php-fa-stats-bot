import { ref, shallowRef } from 'vue';

// Tiny wrapper that drives a one-shot async call with loading/error/data
// state. Re-running the call (e.g. on refresh) resets the previous error.
export function useAsync(fn) {
    const data = shallowRef(null);
    const error = ref(null);
    const loading = ref(false);

    async function run(...args) {
        loading.value = true;
        error.value = null;
        try {
            data.value = await fn(...args);
            return data.value;
        } catch (e) {
            error.value = e.message || String(e);
            throw e;
        } finally {
            loading.value = false;
        }
    }

    return { data, error, loading, run };
}
