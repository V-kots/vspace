import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import api, { extractErrorMessage, setAuthToken } from '../api/client';
import AuthLayout from '../components/AuthLayout';
import FormAlert from '../components/FormAlert';
import ValidationSummary from '../components/ValidationSummary';
import {
    hasFieldError,
    isValidUserId,
    validateLogin,
    validationSummary,
} from '../validation/register';

export default function Login() {
    const navigate = useNavigate();
    const [searchParams, setSearchParams] = useSearchParams();
    const prefilledFromLink = useRef(false);
    const [form, setForm] = useState({ id: '', password: '' });
    const [remember, setRemember] = useState(true);
    const [errors, setErrors] = useState({});
    const [summary, setSummary] = useState(null);
    const [serverError, setServerError] = useState('');

    useEffect(() => {
        const idFromLink = searchParams.get('id')?.trim() ?? '';
        if (!idFromLink || prefilledFromLink.current) {
            return;
        }
        if (!isValidUserId(idFromLink)) {
            return;
        }
        prefilledFromLink.current = true;
        setForm((prev) => ({ ...prev, id: idFromLink }));
        const next = new URLSearchParams(searchParams);
        next.delete('id');
        setSearchParams(next, { replace: true });
    }, [searchParams, setSearchParams]);

    function onChange(e) {
        const { name, value } = e.target;
        setForm((prev) => ({ ...prev, [name]: value }));
        setErrors((prev) => {
            if (!prev[name]) {
                return prev;
            }
            const next = { ...prev };
            delete next[name];
            return next;
        });
        setSummary(null);
        setServerError('');
    }

    async function onSubmit(e) {
        e.preventDefault();
        setServerError('');
        setSummary(null);

        const clientErrors = validateLogin(form);
        setErrors(clientErrors);
        if (Object.keys(clientErrors).length > 0) {
            setSummary(validationSummary(clientErrors));
            return;
        }

        const id = form.id.trim();

        try {
            const { data } = await api.post('/login', {
                id,
                password: form.password,
            });
            setAuthToken(data.token);
            const userId = data.user_id ?? id;
            if (remember) {
                localStorage.setItem('vspace_user_id', userId);
            } else {
                localStorage.removeItem('vspace_user_id');
            }
            navigate('/cabinet');
        } catch (error) {
            setServerError(extractErrorMessage(error));
        }
    }

    function fieldClass(name) {
        return hasFieldError(errors, name) ? 'input-invalid' : '';
    }

    return (
        <AuthLayout
            title="Добро пожаловать!"
            subtitle="Войдите по ID пользователя и паролю из анкеты."
            footer={(
                <p className="muted auth-footer-text">
                    Нет аккаунта?{' '}
                    <Link className="link" to="/register">Зарегистрироваться</Link>
                </p>
            )}
        >
            <ValidationSummary summary={summary} />
            {serverError && <FormAlert>{serverError}</FormAlert>}
            <form className="form-grid" onSubmit={onSubmit} noValidate>
                <label>
                    ID пользователя
                    <input
                        type="text"
                        name="id"
                        value={form.id}
                        onChange={onChange}
                        className={`input-mono ${fieldClass('id')}`.trim()}
                        autoComplete="username"
                        spellCheck={false}
                    />
                    <span className="field-hint">
                        UUID из поля <code>user_id</code> после регистрации
                    </span>
                    {errors.id && <span className="field-error">{errors.id}</span>}
                </label>
                <label>
                    Пароль
                    <input
                        type="password"
                        name="password"
                        value={form.password}
                        onChange={onChange}
                        className={fieldClass('password')}
                        autoComplete="current-password"
                    />
                    {errors.password && <span className="field-error">{errors.password}</span>}
                </label>
                <div className="form-row-between">
                    <label className="checkbox-label">
                        <input
                            type="checkbox"
                            checked={remember}
                            onChange={(e) => setRemember(e.target.checked)}
                        />
                        Запомнить меня
                    </label>
                    <span className="link link-muted" title="Скоро">Забыли пароль?</span>
                </div>
                <button className="btn btn-gradient btn-block" type="submit">Войти</button>
            </form>
        </AuthLayout>
    );
}
