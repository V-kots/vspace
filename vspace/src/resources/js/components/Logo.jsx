import { Link } from 'react-router-dom';

export default function Logo({ size = 'md', to = '/', onNavigate }) {
    const mark = (
        <span
            className={`brand ${size === 'sm' ? 'brand-sm' : ''} ${size === 'lg' ? 'brand-lg' : ''}`}
            aria-hidden={to ? true : undefined}
        >
            <span className="brand-wordmark">VSpace</span>
        </span>
    );

    if (!to) {
        return (
            <div className="brand" aria-label="VSpace">
                <span className="brand-wordmark">VSpace</span>
            </div>
        );
    }

    return (
        <Link to={to} className="brand-link" aria-label="VSpace — на главную" onClick={onNavigate}>
            {mark}
        </Link>
    );
}
