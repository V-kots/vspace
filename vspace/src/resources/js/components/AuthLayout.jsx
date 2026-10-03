import Logo from './Logo';

export default function AuthLayout({ title, subtitle, children, footer }) {
    return (
        <div className="auth-page">
            <div className="auth-page-bg" aria-hidden="true" />
            <div className="auth-shell">
                <header className="auth-header">
                    <Logo size="lg" to="/" />
                    <p className="auth-tagline">Люди. Интересы. Возможности.</p>
                </header>
                <div className="auth-card panel">
                    <h1 className="auth-title">{title}</h1>
                    {subtitle && <p className="auth-subtitle muted">{subtitle}</p>}
                    {children}
                </div>
                {footer && <footer className="auth-footer">{footer}</footer>}
            </div>
        </div>
    );
}
