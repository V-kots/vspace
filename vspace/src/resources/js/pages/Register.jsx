import { useState } from 'react';
import { Link } from 'react-router-dom';
import api, { extractErrorMessage } from '../api/client';
import AuthLayout from '../components/AuthLayout';
import FormAlert from '../components/FormAlert';
import ValidationSummary from '../components/ValidationSummary';
import {
    hasFieldError,
    validateRegister,
    validationSummary,
} from '../validation/register';

const initial = {
    first_name: '',
    second_name: '',
    birthdate: '',
    email: '',
    gender: '',
    biography: '',
    city: '',
    password: '',
    password_confirmation: '',
};

const INTEREST_TAGS = ['Технологии', 'Путешествия', 'Фотография', 'Музыка', 'Спорт', 'Кино'];

export default function Register() {
    const [form, setForm] = useState(initial);
    const [errors, setErrors] = useState({});
    const [summary, setSummary] = useState(null);
    const [serverError, setServerError] = useState('');
    const [registeredUserId, setRegisteredUserId] = useState(null);

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

    function toggleInterest(tag) {
        setForm((prev) => {
            const parts = prev.biography.split(',').map((s) => s.trim()).filter(Boolean);
            const has = parts.includes(tag);
            const next = has ? parts.filter((p) => p !== tag) : [...parts, tag];
            return { ...prev, biography: next.join(', ') };
        });
    }

    function isInterestActive(tag) {
        return form.biography.split(',').map((s) => s.trim()).includes(tag);
    }

    async function onSubmit(e) {
        e.preventDefault();
        setServerError('');
        setSummary(null);

        const clientErrors = validateRegister(form);
        setErrors(clientErrors);
        if (Object.keys(clientErrors).length > 0) {
            setSummary(validationSummary(clientErrors));
            return;
        }

        try {
            const { data } = await api.post('/user/register', {
                first_name: form.first_name.trim(),
                second_name: form.second_name.trim(),
                birthdate: form.birthdate,
                email: form.email.trim(),
                gender: form.gender,
                biography: form.biography.trim(),
                city: form.city.trim(),
                password: form.password,
            });
            localStorage.setItem('vspace_user_id', data.user_id);
            setRegisteredUserId(data.user_id);
        } catch (error) {
            setServerError(extractErrorMessage(error));
        }
    }

    function fieldClass(name) {
        return hasFieldError(errors, name) ? 'input-invalid' : '';
    }

    return (
        <AuthLayout
            title="Создание аккаунта"
            subtitle="Заполните анкету — после регистрации сохраните ID для входа."
            footer={!registeredUserId && (
                <p className="muted auth-footer-text">
                    Уже есть аккаунт?{' '}
                    <Link className="link" to="/login">Войти</Link>
                </p>
            )}
        >
            {registeredUserId ? (
                <div className="register-done">
                    <FormAlert variant="success">
                        Анкета успешно создана. Сохраните ID пользователя — он нужен для входа вместе с паролем.
                    </FormAlert>
                    <p className="register-done-label muted">ID пользователя</p>
                    <code className="user-id-value">{registeredUserId}</code>
                    <Link
                        className="btn btn-gradient btn-block register-done-login"
                        to={`/login?id=${encodeURIComponent(registeredUserId)}`}
                    >
                        Войти в аккаунт
                    </Link>
                </div>
            ) : (
                <>
            <ValidationSummary summary={summary} />
            {serverError && <FormAlert>{serverError}</FormAlert>}
            <form className="form-grid" action="#" method="post" onSubmit={onSubmit} noValidate>
                <div className="form-row-2">
                    <label>
                        Имя
                        <input
                            name="first_name"
                            value={form.first_name}
                            onChange={onChange}
                            className={fieldClass('first_name')}
                            autoComplete="given-name"
                        />
                        {errors.first_name && <span className="field-error">{errors.first_name}</span>}
                    </label>
                    <label>
                        Фамилия
                        <input
                            name="second_name"
                            value={form.second_name}
                            onChange={onChange}
                            className={fieldClass('second_name')}
                            autoComplete="family-name"
                        />
                        {errors.second_name && <span className="field-error">{errors.second_name}</span>}
                    </label>
                </div>
                <div className="form-row-2">
                    <label>
                        Дата рождения
                        <input
                            type="date"
                            name="birthdate"
                            value={form.birthdate}
                            onChange={onChange}
                            className={fieldClass('birthdate')}
                        />
                        {errors.birthdate && <span className="field-error">{errors.birthdate}</span>}
                    </label>
                    <label>
                        Город
                        <input
                            name="city"
                            value={form.city}
                            onChange={onChange}
                            className={fieldClass('city')}
                            autoComplete="address-level2"
                        />
                        {errors.city && <span className="field-error">{errors.city}</span>}
                    </label>
                </div>
                <label>
                    Email
                    <input
                        type="email"
                        name="email"
                        value={form.email}
                        onChange={onChange}
                        className={fieldClass('email')}
                        autoComplete="email"
                    />
                    {errors.email && <span className="field-error">{errors.email}</span>}
                </label>
                <fieldset className={`gender-fieldset ${errors.gender ? 'has-error' : ''}`}>
                    <legend>Пол</legend>
                    <div className="gender-options">
                        {[
                            { value: 'male', label: 'Мужской' },
                            { value: 'female', label: 'Женский' },
                            { value: 'other', label: 'Другой' },
                        ].map((opt) => (
                            <label key={opt.value} className={`gender-pill ${form.gender === opt.value ? 'active' : ''}`}>
                                <input
                                    type="radio"
                                    name="gender"
                                    value={opt.value}
                                    checked={form.gender === opt.value}
                                    onChange={onChange}
                                />
                                {opt.label}
                            </label>
                        ))}
                    </div>
                    {errors.gender && <span className="field-error">{errors.gender}</span>}
                </fieldset>
                <div className="interests-block">
                    <span className="interests-label">Интересы</span>
                    <div className="tag-list">
                        {INTEREST_TAGS.map((tag) => (
                            <button
                                key={tag}
                                type="button"
                                className={`tag-chip ${isInterestActive(tag) ? 'active' : ''}`}
                                onClick={() => toggleInterest(tag)}
                            >
                                {tag}
                            </button>
                        ))}
                    </div>
                    <textarea
                        name="biography"
                        rows={2}
                        value={form.biography}
                        onChange={onChange}
                        placeholder="Или опишите интересы своими словами"
                        className={`interests-textarea ${fieldClass('biography')}`}
                    />
                    {errors.biography && <span className="field-error">{errors.biography}</span>}
                    <span className="field-hint">Необязательно. Теги попадут в поле «Интересы» анкеты.</span>
                </div>
                <label>
                    Пароль
                    <input
                        type="password"
                        name="password"
                        value={form.password}
                        onChange={onChange}
                        className={fieldClass('password')}
                        autoComplete="new-password"
                    />
                    {errors.password
                        ? <span className="field-error">{errors.password}</span>
                        : <span className="field-hint">Не менее 8 символов.</span>}
                </label>
                <label>
                    Повторить пароль
                    <input
                        type="password"
                        name="password_confirmation"
                        value={form.password_confirmation}
                        onChange={onChange}
                        className={fieldClass('password_confirmation')}
                        autoComplete="new-password"
                    />
                    {errors.password_confirmation && (
                        <span className="field-error">{errors.password_confirmation}</span>
                    )}
                </label>
                <button className="btn btn-gradient btn-block" type="submit">Зарегистрироваться</button>
            </form>
                </>
            )}
        </AuthLayout>
    );
}
