export default function ValidationSummary({ summary }) {
    if (!summary) {
        return null;
    }

    return (
        <div className="validation-summary" role="alert" aria-live="polite">
            <div className="validation-summary-header">
                <span className="validation-summary-icon" aria-hidden="true">!</span>
                <p className="validation-summary-title">{summary.title}</p>
            </div>
            <ul className="validation-summary-list">
                {summary.items.map((item) => (
                    <li key={item.field}>
                        <span className="validation-summary-field">{item.field}</span>
                        <span className="validation-summary-message">{item.message}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
