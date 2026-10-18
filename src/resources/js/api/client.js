import axios from 'axios';

const api = axios.create({
    baseURL: '/',
    headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
    },
});

export function setAuthToken(token) {
    if (token) {
        api.defaults.headers.common.Authorization = `Bearer ${token}`;
        localStorage.setItem('vspace_token', token);
    } else {
        delete api.defaults.headers.common.Authorization;
        localStorage.removeItem('vspace_token');
    }
}

const saved = localStorage.getItem('vspace_token');
if (saved) {
    setAuthToken(saved);
}

export function extractErrorMessage(error) {
    if (error.response?.data?.message) {
        return error.response.data.message;
    }
    if (error.response?.status === 404) {
        return 'Ресурс не найден';
    }
    return 'Произошла ошибка. Попробуйте позже.';
}

export default api;
