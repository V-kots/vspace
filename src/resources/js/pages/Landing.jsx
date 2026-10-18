import { Link } from 'react-router-dom';
import Logo from '../components/Logo';

export default function Landing() {
    return (
        <div className="landing-page">
            <div className="landing-bg" aria-hidden="true" />
            <div className="landing-shell">
                <Logo size="lg" to="/" />
                <p className="landing-tagline">Люди. Интересы. Возможности.</p>
                <p className="landing-lead">
                    Социальная сеть для знакомств и общения по интересам. Создайте анкету и находите людей в своём городе.
                </p>
                <div className="landing-actions">
                    <Link className="btn btn-gradient" to="/register">Регистрация</Link>
                    <Link className="btn btn-outline" to="/login">Войти</Link>
                </div>
            </div>
        </div>
    );
}
