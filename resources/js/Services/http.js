import axios from 'axios';

/**
 * Central Axios instance for REST calls made from the browser (Inertia handles page navigation itself).
 * Laravel's XSRF-TOKEN cookie is picked up automatically for session-authenticated requests.
 */
const http = axios.create({
    baseURL: '/api/v1',
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    withCredentials: true,
});

/** Normalise API errors to { message, errors } so components handle them one way. */
export function parseError(error) {
    const data = error?.response?.data ?? {};
    return {
        status: error?.response?.status ?? 0,
        message: data.message ?? 'Something went wrong. Please try again.',
        errors: data.errors ?? {},
    };
}

export default http;
