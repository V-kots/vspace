import { useState } from 'react';

export default function UserSearchForm({
    initialFirstName = '',
    initialLastName = '',
    onSubmit,
    className = '',
    autoFocus = false,
}) {
    const [firstName, setFirstName] = useState(initialFirstName);
    const [lastName, setLastName] = useState(initialLastName);

    function handleSubmit(event) {
        event.preventDefault();

        const first = firstName.trim();
        const last = lastName.trim();

        if (!first || !last) {
            return;
        }

        onSubmit?.(first, last);
    }

    return (
        <form className={`user-search-form ${className}`.trim()} onSubmit={handleSubmit} role="search">
            <input
                type="search"
                className="search-input"
                placeholder="Имя"
                aria-label="Имя"
                value={firstName}
                onChange={(event) => setFirstName(event.target.value)}
                autoFocus={autoFocus}
                required
            />
            <input
                type="search"
                className="search-input"
                placeholder="Фамилия"
                aria-label="Фамилия"
                value={lastName}
                onChange={(event) => setLastName(event.target.value)}
                required
            />
            <button type="submit" className="btn btn-gradient">Найти</button>
        </form>
    );
}
