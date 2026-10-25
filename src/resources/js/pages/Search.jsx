import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import api, { extractErrorMessage } from '../api/client';
import AppShell from '../components/AppShell';
import UserSearchForm from '../components/UserSearchForm';

export default function Search() {
    const navigate = useNavigate();
    const [params, setParams] = useSearchParams();
    const [userId, setUserId] = useState('');
    const [results, setResults] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const lastQuery = useRef('');

    const queryString = params.toString();

    useEffect(() => {
        const token = localStorage.getItem('vspace_token');
        const storedId = localStorage.getItem('vspace_user_id');

        if (!token || !storedId) {
            navigate('/login');
            return;
        }

        setUserId(storedId);
    }, [navigate]);

    useEffect(() => {
        const first = params.get('first_name');
        const last = params.get('last_name');

        if (!first || !last || lastQuery.current === queryString) {
            return;
        }

        lastQuery.current = queryString;
        search(first, last);
    }, [queryString]);

    async function search(first, last) {
        setLoading(true);
        setError('');

        try {
            const { data } = await api.get('/user/search', {
                params: { first_name: first, last_name: last },
            });
            setResults(data);
        } catch (requestError) {
            setError(extractErrorMessage(requestError));
            setResults([]);
        } finally {
            setLoading(false);
        }
    }

    function handleSubmit(first, last) {
        setParams({ first_name: first, last_name: last });
    }

    if (!userId) {
        return <div className="app-loading muted">Загрузка...</div>;
    }

    const searched = results !== null;

    return (
        <AppShell userId={userId}>
            <div className="panel search-panel">
                <h1 className="section-title">Поиск анкет</h1>
                <p className="muted search-hint">
                    Введите начало имени и фамилии — поиск идёт одновременно по обоим полям.
                </p>
                <UserSearchForm
                    key={queryString}
                    initialFirstName={params.get('first_name') || ''}
                    initialLastName={params.get('last_name') || ''}
                    onSubmit={handleSubmit}
                />
            </div>

            {error ? <div className="alert alert-error">{error}</div> : null}

            {loading ? <div className="app-loading muted">Ищем анкеты...</div> : null}

            {!loading && searched && (
                <div className="search-results-wrap">
                    <p className="muted search-count">
                        Найдено: <strong>{results.length}</strong>
                    </p>

                    {results.length === 0 ? (
                        <div className="panel search-empty muted">
                            По заданным имени и фамилии никого не нашли. Попробуйте укоротить запрос.
                        </div>
                    ) : (
                        <ul className="search-results">
                            {results.map((profile) => {
                                const fullName = `${profile.first_name} ${profile.second_name}`;

                                return (
                                    <li key={profile.id}>
                                        <Link className="panel search-result" to={`/profile/${profile.id}`}>
                                            <span className="search-result-avatar">
                                                {profile.first_name?.charAt(0) || '?'}
                                            </span>
                                            <span className="search-result-body">
                                                <span className="search-result-name">{fullName}</span>
                                                <span className="muted search-result-meta">
                                                    {profile.city}
                                                    {profile.birthdate ? ` · ${profile.birthdate}` : ''}
                                                </span>
                                                {profile.biography ? (
                                                    <span className="search-result-bio">{profile.biography}</span>
                                                ) : null}
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            )}
        </AppShell>
    );
}
