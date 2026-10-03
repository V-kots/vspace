const FIELD_LABELS = {
    first_name: 'Имя',
    second_name: 'Фамилия',
    birthdate: 'Дата рождения',
    email: 'Email',
    gender: 'Пол',
    city: 'Город',
    password: 'Пароль',
    password_confirmation: 'Подтверждение пароля',
    biography: 'Интересы',
    id: 'ID пользователя',
};

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const GENDERS = new Set(['male', 'female', 'other']);
const BIOGRAPHY_MAX = 5000;
export const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;

export function isValidUserId(value) {
    return UUID_RE.test(String(value ?? '').trim());
}
const NAME_RE = /^[\p{L}\s'-]+$/u;

function parseBirthdate(value) {
    if (!value) {
        return null;
    }
    const parts = value.split('-').map(Number);
    if (parts.length !== 3) {
        return null;
    }
    const [year, month, day] = parts;
    const date = new Date(year, month - 1, day);
    if (
        date.getFullYear() !== year
        || date.getMonth() !== month - 1
        || date.getDate() !== day
    ) {
        return null;
    }
    return date;
}

export function validateRegister(form) {
    const errors = {};
    const firstName = form.first_name?.trim() ?? '';
    const secondName = form.second_name?.trim() ?? '';
    const email = form.email?.trim() ?? '';
    const city = form.city?.trim() ?? '';

    if (!firstName) {
        errors.first_name = 'Поле «Имя» обязательно. Введите ваше имя.';
    } else if (firstName.length < 2) {
        errors.first_name = 'Имя слишком короткое: нужно минимум 2 символа.';
    } else if (firstName.length > 100) {
        errors.first_name = 'Имя не должно быть длиннее 100 символов.';
    } else if (!NAME_RE.test(firstName)) {
        errors.first_name = 'Имя может содержать только буквы, пробел, дефис или апостроф.';
    }

    if (!secondName) {
        errors.second_name = 'Поле «Фамилия» обязательно. Введите вашу фамилию.';
    } else if (secondName.length < 2) {
        errors.second_name = 'Фамилия слишком короткая: нужно минимум 2 символа.';
    } else if (secondName.length > 100) {
        errors.second_name = 'Фамилия не должна быть длиннее 100 символов.';
    } else if (!NAME_RE.test(secondName)) {
        errors.second_name = 'Фамилия может содержать только буквы, пробел, дефис или апостроф.';
    }

    if (!form.birthdate) {
        errors.birthdate = 'Укажите дату рождения — выберите день в календаре.';
    } else {
        const birth = parseBirthdate(form.birthdate);
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        if (!birth) {
            errors.birthdate = 'Дата рождения указана неверно. Проверьте формат ГГГГ-ММ-ДД.';
        } else if (birth >= today) {
            errors.birthdate = 'Дата рождения должна быть в прошлом (вы не можете указать сегодня или будущую дату).';
        } else {
            const ageCutoff = new Date(today.getFullYear() - 120, today.getMonth(), today.getDate());
            if (birth < ageCutoff) {
                errors.birthdate = 'Проверьте дату рождения — указан нереалистичный возраст.';
            }
        }
    }

    if (!email) {
        errors.email = 'Поле «Email» обязательно. Укажите адрес, на который можно войти в аккаунт.';
    } else if (!EMAIL_RE.test(email)) {
        errors.email = 'Email выглядит некорректно. Пример правильного формата: name@example.com';
    } else if (email.length > 255) {
        errors.email = 'Email слишком длинный (максимум 255 символов).';
    }

    if (!form.gender) {
        errors.gender = 'Выберите пол из списка — поле обязательно для анкеты.';
    } else if (!GENDERS.has(form.gender)) {
        errors.gender = 'Выберите одно из допустимых значений: мужской, женский или другой.';
    }

    const biography = form.biography?.trim() ?? '';
    if (biography.length > BIOGRAPHY_MAX) {
        errors.biography = `Текст интересов слишком длинный: максимум ${BIOGRAPHY_MAX} символов.`;
    }

    if (!city) {
        errors.city = 'Поле «Город» обязательно. Укажите город проживания.';
    } else if (city.length > 150) {
        errors.city = 'Название города не должно быть длиннее 150 символов.';
    }

    if (!form.password) {
        errors.password = 'Придумайте пароль — поле не может быть пустым.';
    } else if (form.password.length < 8) {
        errors.password = `Пароль слишком короткий: сейчас ${form.password.length} символ(ов), нужно минимум 8.`;
    } else if (form.password.length > 255) {
        errors.password = 'Пароль не должен быть длиннее 255 символов.';
    }

    if (!form.password_confirmation) {
        errors.password_confirmation = 'Повторите пароль — так мы убедимся, что вы не опечатались.';
    } else if (form.password !== form.password_confirmation) {
        errors.password_confirmation = 'Пароли не совпадают. Введите один и тот же пароль в оба поля.';
    }

    return errors;
}

export function validateLogin(form) {
    const errors = {};
    const id = form.id?.trim() ?? '';

    if (!id) {
        errors.id = 'Введите ID пользователя — его выдаёт сервер после регистрации.';
    } else if (!UUID_RE.test(id)) {
        errors.id = 'ID должен быть UUID, например: e4d2e6b0-cde2-42c5-aac3-0b8316f21e58';
    }

    if (!form.password) {
        errors.password = 'Введите пароль от вашего аккаунта.';
    }

    return errors;
}

export function validationSummary(errors) {
    const keys = Object.keys(errors);
    if (keys.length === 0) {
        return null;
    }

    const count = keys.length;
    const mod100 = count % 100;
    const mod10 = count % 10;
    let title;
    if (count === 1) {
        title = 'Исправьте ошибку в форме:';
    } else if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
        title = `Исправьте ${count} ошибки в форме:`;
    } else {
        title = `Исправьте ${count} ошибок в форме:`;
    }

    const items = keys.map((key) => ({
        field: FIELD_LABELS[key] ?? key,
        message: errors[key],
    }));

    return { title, items };
}

export function hasFieldError(errors, name) {
    return Boolean(errors[name]);
}
