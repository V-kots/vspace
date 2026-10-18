export default function FormAlert({ variant = 'error', children }) {
    const icon = variant === 'success' ? '✓' : '!';

    return (
        <div className={`form-alert form-alert-${variant}`} role="alert">
            <span className={`form-alert-icon form-alert-icon-${variant}`} aria-hidden="true">{icon}</span>
            <div className="form-alert-body">{children}</div>
        </div>
    );
}
