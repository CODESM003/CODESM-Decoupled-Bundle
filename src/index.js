import { useState, useEffect, createRoot } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import {
    TextControl,
    TextareaControl,
    ToggleControl,
    Button,
    Notice,
    Spinner,
    CheckboxControl,
    SelectControl,
    Modal,
} from '@wordpress/components';
import './styles.scss';

const { restUrl, restNonce, initialSettings, initialLog, i18n } =
    window.codesmDecoupledBundle || {};

apiFetch.use(apiFetch.createNonceMiddleware(restNonce));

const TrashIcon = () => <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="currentColor"><path d="M19 6.4L17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6l1.4 1.4L12 13.4l5.6 5.6 1.4-1.4L13.4 12 19 6.4Z"/></svg>;

const TABS = [
    { id: 'site',        label: 'Site' },
    { id: 'contact',     label: 'Contact' },
    { id: 'social',      label: 'Social' },
    { id: 'scripts',     label: 'Scripts' },
    { id: 'maintenance', label: 'Maintenance' },
    { id: 'build',       label: 'Build' },
    { id: 'deployments', label: 'Deployments' },
    { id: 'plugin-updates', label: 'Plugin Updates' },
];

const SOCIAL_NETWORKS = [
    // General
    { key: 'facebook',  label: 'Facebook',    placeholder: 'https://facebook.com/yourpage',             group: 'general' },
    { key: 'instagram', label: 'Instagram',   placeholder: 'https://instagram.com/yourhandle',          group: 'general' },
    { key: 'twitter',   label: 'Twitter / X', placeholder: 'https://x.com/yourhandle',                  group: 'general' },
    { key: 'linkedin',  label: 'LinkedIn',    placeholder: 'https://linkedin.com/company/yourcompany',  group: 'general' },
    { key: 'youtube',   label: 'YouTube',     placeholder: 'https://youtube.com/@yourchannel',          group: 'general' },
    { key: 'tiktok',    label: 'TikTok',      placeholder: 'https://tiktok.com/@yourhandle',            group: 'general' },
    { key: 'threads',   label: 'Threads',     placeholder: 'https://threads.net/@yourhandle',           group: 'general' },
    { key: 'pinterest', label: 'Pinterest',   placeholder: 'https://pinterest.com/yourprofile',         group: 'general' },
    { key: 'snapchat',  label: 'Snapchat',    placeholder: 'https://snapchat.com/add/yourhandle',       group: 'general' },
    // Messaging
    { key: 'whatsapp',  label: 'WhatsApp',    placeholder: 'https://wa.me/1234567890',                  group: 'messaging' },
    { key: 'telegram',  label: 'Telegram',    placeholder: 'https://t.me/yourchannel',                  group: 'messaging' },
    { key: 'discord',   label: 'Discord',     placeholder: 'https://discord.gg/invite',                 group: 'messaging' },
    { key: 'reddit',    label: 'Reddit',      placeholder: 'https://reddit.com/r/yourcommunity',        group: 'messaging' },
    // Creative
    { key: 'vimeo',     label: 'Vimeo',       placeholder: 'https://vimeo.com/yourchannel',             group: 'creative' },
    { key: 'github',    label: 'GitHub',      placeholder: 'https://github.com/yourprofile',            group: 'creative' },
    { key: 'behance',   label: 'Behance',     placeholder: 'https://behance.net/yourprofile',           group: 'creative' },
    { key: 'dribbble',  label: 'Dribbble',    placeholder: 'https://dribbble.com/yourhandle',           group: 'creative' },
];

// ─────────────────────────────────────────────────────────────────────────────
// Utilities
// ─────────────────────────────────────────────────────────────────────────────

const formatDate = (iso) => {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString(undefined, {
            year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit',
            timeZone: 'UTC', timeZoneName: 'short',
        });
    } catch {
        return iso;
    }
};

const unixToUtcDateTime = (unixTimestamp) => {
    if (!unixTimestamp) return '';
    return new Date(unixTimestamp * 1000).toISOString().slice(0, 16);
};

const utcDateTimeToUnix = (utcDateTime) => {
    if (!utcDateTime) return 0;
    return Math.floor(new Date(utcDateTime + 'Z').getTime() / 1000);
};

const formatCurrentUtcTime = () => {
    const now = new Date();
    return now.toISOString().replace('T', ' ').slice(0, 19) + ' UTC';
};

const RUN_STATUS_MAP = {
    queued:      { label: 'Queued',    className: 'codesm-decoupled-bundle-status--queued' },
    in_progress: { label: 'Running…',  className: 'codesm-decoupled-bundle-status--running' },
    success:         { label: 'Success',         className: 'codesm-decoupled-bundle-status--success' },
    failure:         { label: 'Failed',          className: 'codesm-decoupled-bundle-status--failed' },
    cancelled:       { label: 'Cancelled',       className: 'codesm-decoupled-bundle-status--cancelled' },
    timed_out:       { label: 'Timed out',       className: 'codesm-decoupled-bundle-status--failed' },
    action_required: { label: 'Action required', className: 'codesm-decoupled-bundle-status--warning' },
    skipped:         { label: 'Skipped',         className: 'codesm-decoupled-bundle-status--cancelled' },
    neutral:         { label: 'Neutral',         className: 'codesm-decoupled-bundle-status--cancelled' },
};

const runStatus = (status, conclusion) => {
    if (RUN_STATUS_MAP[status]) return RUN_STATUS_MAP[status];
    if (status === 'completed') return RUN_STATUS_MAP[conclusion] ?? { label: conclusion ?? 'Unknown', className: 'codesm-decoupled-bundle-status--unknown' };
    return { label: status ?? 'Unknown', className: 'codesm-decoupled-bundle-status--unknown' };
};

// ─────────────────────────────────────────────────────────────────────────────
// LocationRow — collapsible location card
// ─────────────────────────────────────────────────────────────────────────────

function LocationRow({ loc, index, onChange, onRemove }) {
    const [expanded, setExpanded] = useState(false);
    const set = (field) => (v) => onChange(index, { ...loc, address: { ...loc.address, [field]: v } });
    const setCoord = (field) => (v) => onChange(index, { ...loc, coordinates: { ...loc.coordinates, [field]: v } });
    return (
        <div className="codesm-decoupled-bundle-page-row">
            <div className="codesm-decoupled-bundle-page-row__header">
                <TextControl
                    value={loc.label || ''}
                    onChange={(v) => onChange(index, { ...loc, label: v })}
                    placeholder="Main Office, Warehouse…"
                    __nextHasNoMarginBottom
                    className="codesm-decoupled-bundle-page-row__url"
                />
                <Button variant="tertiary" onClick={() => setExpanded(x => !x)}>{expanded ? '▲' : '▼'}</Button>
                <Button variant="tertiary" isDestructive children={<TrashIcon />} onClick={() => onRemove(index)} label="Remove" />
            </div>
            {expanded && (
                <div className="codesm-decoupled-bundle-page-row__body">
                    <div className="codesm-decoupled-bundle-field">
                        <TextControl label="Street" value={loc.address?.street || ''} onChange={set('street')} placeholder="123 Main St" __nextHasNoMarginBottom />
                    </div>
                    <div className="codesm-decoupled-bundle-field-row">
                        <div className="codesm-decoupled-bundle-field">
                            <TextControl label="City" value={loc.address?.city || ''} onChange={set('city')} __nextHasNoMarginBottom />
                        </div>
                        <div className="codesm-decoupled-bundle-field">
                            <TextControl label="State / Province" value={loc.address?.state || ''} onChange={set('state')} __nextHasNoMarginBottom />
                        </div>
                        <div className="codesm-decoupled-bundle-field">
                            <TextControl label="ZIP / Postal Code" value={loc.address?.zip || ''} onChange={set('zip')} __nextHasNoMarginBottom />
                        </div>
                    </div>
                    <div className="codesm-decoupled-bundle-field-row">
                        <div className="codesm-decoupled-bundle-field">
                            <TextControl label="Country" value={loc.address?.country || ''} onChange={set('country')} __nextHasNoMarginBottom />
                        </div>
                        <div className="codesm-decoupled-bundle-field">
                            <TextControl label="Latitude" value={loc.coordinates?.lat || ''} onChange={setCoord('lat')} placeholder="40.7128" __nextHasNoMarginBottom />
                        </div>
                        <div className="codesm-decoupled-bundle-field">
                            <TextControl label="Longitude" value={loc.coordinates?.lng || ''} onChange={setCoord('lng')} placeholder="-74.0060" __nextHasNoMarginBottom />
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// ScriptFields — reusable header / body-start / body-end textareas
// ─────────────────────────────────────────────────────────────────────────────

function ScriptFields({ values, onChange, showJsonLd = false }) {
    const set = (key) => (value) => onChange({ ...values, [key]: value });
    return (
        <div className="codesm-decoupled-bundle-script-fields">
            {showJsonLd && (
                <div className="codesm-decoupled-bundle-field">
                    <TextareaControl
                        label="JSON-LD"
                        help="Structured data — without wrapping <script> tags."
                        value={values.json_ld || ''}
                        onChange={set('json_ld')}
                        rows={5}
                        className="codesm-decoupled-bundle-code-field"
                        __nextHasNoMarginBottom
                    />
                </div>
            )}
            <div className="codesm-decoupled-bundle-field">
                <TextareaControl
                    label="Header Scripts"
                    help="Injected inside <head>. Include full <script> or <link> tags."
                    value={values.header || ''}
                    onChange={set('header')}
                    rows={4}
                    className="codesm-decoupled-bundle-code-field"
                    __nextHasNoMarginBottom
                />
            </div>
            <div className="codesm-decoupled-bundle-field">
                <TextareaControl
                    label="Body Start Scripts"
                    help="Injected immediately after <body> opens."
                    value={values.body_start || ''}
                    onChange={set('body_start')}
                    rows={4}
                    className="codesm-decoupled-bundle-code-field"
                    __nextHasNoMarginBottom
                />
            </div>
            <div className="codesm-decoupled-bundle-field">
                <TextareaControl
                    label="Body End Scripts"
                    help="Injected just before </body> closes."
                    value={values.body_end || ''}
                    onChange={set('body_end')}
                    rows={4}
                    className="codesm-decoupled-bundle-code-field"
                    __nextHasNoMarginBottom
                />
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// PageScriptRow — a single per-page rule with collapsible script fields
// ─────────────────────────────────────────────────────────────────────────────

function PageScriptRow({ entry, index, onChange, onRemove }) {
    const [expanded, setExpanded] = useState(false);
    return (
        <div className="codesm-decoupled-bundle-page-row">
            <div className="codesm-decoupled-bundle-page-row__header">
                <TextControl
                    value={entry.url_pattern || ''}
                    onChange={(v) => onChange(index, { ...entry, url_pattern: v })}
                    placeholder="/about  or  /blog/*"
                    __nextHasNoMarginBottom
                    className="codesm-decoupled-bundle-page-row__url"
                />
                <Button variant="tertiary" onClick={() => setExpanded((x) => !x)}>
                    {expanded ? '▲' : '▼'}
                </Button>
                <Button variant="tertiary" isDestructive children={<TrashIcon />} onClick={() => onRemove(index)} label="Remove" />
            </div>
            {expanded && (
                <div className="codesm-decoupled-bundle-page-row__body">
                    <ScriptFields
                        values={entry}
                        onChange={(updated) => onChange(index, updated)}
                        showJsonLd
                    />
                </div>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// UtcClock — live updating clock
// ─────────────────────────────────────────────────────────────────────────────

function UtcClock() {
    const [time, setTime] = useState(() => formatCurrentUtcTime());

    useEffect(() => {
        const interval = setInterval(() => {
            setTime(formatCurrentUtcTime());
        }, 1000);
        return () => clearInterval(interval);
    }, []);

    return (
        <div style={{ backgroundColor: '#f5f5f5', padding: '12px', borderRadius: '4px', marginBottom: '16px' }}>
            <p style={{ margin: '0', fontSize: '0.9em', color: '#333' }}>
                <strong>Current UTC time:</strong> <code>{time}</code>
            </p>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// DeploymentHistory — GitHub runs table + local WP dispatch log
// ─────────────────────────────────────────────────────────────────────────────

function Countdown({ targetIso }) {
    const calc = () => Math.max(0, Math.floor((new Date(targetIso) - Date.now()) / 1000));
    const [secs, setSecs] = useState(calc);

    useEffect(() => {
        const id = setInterval(() => {
            const remaining = calc();
            setSecs(remaining);
            if (remaining === 0) clearInterval(id);
        }, 1000);
        return () => clearInterval(id);
    }, [targetIso]);

    if (secs <= 0) return null;
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    const label = m > 0 ? `${m}m ${s}s` : `${s}s`;
    return <span className="codesm-decoupled-bundle-countdown">in {label}</span>;
}

function DeploymentHistory({ repo, onShowNotice }) {
    const [data, setData]           = useState(null);
    const [loading, setLoading]     = useState(false);
    const [error, setError]         = useState(null);
    const [cancelling, setCancelling]           = useState(false);
    const [showCancelConfirm, setShowCancelConfirm] = useState(false);
    const [clearing, setClearing]                   = useState(false);
    const [showClearConfirm, setShowClearConfirm]   = useState(false);
    const [clearError, setClearError]               = useState(null);

    const cancelBuild = async () => {
        setCancelling(true);
        try {
            await apiFetch({ url: `${restUrl}/cancel-build`, method: 'POST' });
            await fetchBuilds();
        } catch {
            // silently ignore — fetchBuilds will show stale data
        } finally {
            setCancelling(false);
        }
    };

    const clearLogs = async () => {
        setClearing(true);
        setClearError(null);
        try {
            const result = await apiFetch({ url: `${restUrl}/clear-logs`, method: 'POST' });
            if (result.success) {
                await fetchBuilds();
                setShowClearConfirm(false);
                if (onShowNotice) {
                    onShowNotice('success', 'Build logs cleared successfully.');
                }
            } else {
                setClearError('Failed to clear logs.');
            }
        } catch (err) {
            setClearError(err.message || 'Failed to clear logs.');
        } finally {
            setClearing(false);
        }
    };

    const fetchBuilds = async (skipCache = false) => {
        setLoading(true);
        setError(null);
        try {
            const url = skipCache
                ? `${restUrl}/builds?_t=${Date.now()}`
                : `${restUrl}/builds`;
            const result = await apiFetch({ url });
            setData(result);
            if (result.error) setError(result.error);
        } catch (err) {
            setError(err.message || 'Failed to fetch builds.');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => { fetchBuilds(); }, []);

    const runs           = data?.runs             ?? [];
    const localLog       = data?.local_log        ?? initialLog ?? [];
    const nextScheduled  = data?.next_scheduled          ?? null;
    const nextTargets    = data?.next_scheduled_targets   ?? [];

    return (
        <div className="codesm-decoupled-bundle-deployments">
            <div className="codesm-decoupled-bundle-builds-header">
                <p className="codesm-decoupled-bundle-panel-desc">
                    Live status from GitHub Actions. The Source column shows whether the
                    build was triggered from WordPress or by a code push.
                </p>
                <Button variant="secondary" onClick={() => fetchBuilds(true)} isBusy={loading} disabled={loading}>
                    {loading ? 'Refreshing…' : 'Refresh'}
                </Button>
            </div>

            {error && <Notice status="error" isDismissible={false}>{error}</Notice>}

            {loading && !data && (
                <div className="codesm-decoupled-bundle-builds-loading">
                    <Spinner /><span>Fetching runs from GitHub…</span>
                </div>
            )}

            {!loading && runs.length === 0 && data !== null && !error && (
                <p className="codesm-decoupled-bundle-builds-empty">No workflow runs found.</p>
            )}

            {nextScheduled && (
                <>
                    <div className="codesm-decoupled-bundle-next-build">
                        <strong>Next auto-build:</strong>
                        <span>{formatDate(nextScheduled)}</span>
                        {repo && <><span>:</span><code>{repo}</code></>}
                        {nextTargets.length > 0 && nextTargets.map(t => (
                            <code key={t.ref}>{t.ref} ({t.workflow_count} workflow{t.workflow_count !== 1 ? 's' : ''})</code>
                        ))}
                        <Countdown targetIso={nextScheduled} />
                        <Button
                            variant="primary"
                            isDestructive
                            isBusy={cancelling}
                            disabled={cancelling}
                            onClick={() => setShowCancelConfirm(true)}
                            style={{ marginLeft: 'auto', flexShrink: 0 }}
                        >
                            {cancelling ? 'Cancelling…' : 'Cancel Scheduled Build'}
                        </Button>
                    </div>

                    {showCancelConfirm && (
                        <Modal
                            title="Cancel Scheduled Build"
                            onRequestClose={() => setShowCancelConfirm(false)}
                            size="small"
                        >
                            <p>Are you sure you want to cancel the scheduled auto-build? The pending deployment will be unscheduled and will not run.</p>
                            <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '16px' }}>
                                <Button variant="secondary" onClick={() => setShowCancelConfirm(false)}>
                                    Keep Scheduled
                                </Button>
                                <Button
                                    variant="primary"
                                    isDestructive
                                    isBusy={cancelling}
                                    onClick={() => { setShowCancelConfirm(false); cancelBuild(); }}
                                >
                                    Yes, Cancel It
                                </Button>
                            </div>
                        </Modal>
                    )}
                </>
            )}

            {runs.length > 0 && (
                <div className="codesm-decoupled-bundle-builds-table-wrap">
                    <table className="codesm-decoupled-bundle-builds-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>#</th>
                                <th>Workflow</th>
                                <th>Source</th>
                                <th>Branch</th>
                                <th>Commit</th>
                                <th>Started</th>
                                <th>Duration</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            {runs.map((run) => {
                                const { label, className } = runStatus(run.status, run.conclusion);
                                const srcClass = run.source?.startsWith('WP')
                                    ? 'codesm-decoupled-bundle-source--wp'
                                    : 'codesm-decoupled-bundle-source--external';
                                return (
                                    <tr
                                        key={run.id}
                                        className={[
                                            'codesm-decoupled-bundle-builds-row',
                                            run.status === 'in_progress' ? 'codesm-decoupled-bundle-is-running' : '',
                                        ].filter(Boolean).join(' ')}
                                    >
                                        <td>
                                            <span className={`codesm-decoupled-bundle-status-badge ${className}`}>{label}</span>
                                        </td>
                                        <td className="codesm-decoupled-bundle-run-num">#{run.run_number}</td>
                                        <td className="codesm-decoupled-bundle-workflow-name">{run.name}</td>
                                        <td>
                                            <span className={`codesm-decoupled-bundle-source-badge ${srcClass}`}>{run.source}</span>
                                        </td>
                                        <td className="codesm-decoupled-bundle-mono">{run.head_branch}</td>
                                        <td className="codesm-decoupled-bundle-mono codesm-decoupled-bundle-sha">
                                            <a href={run.html_url} target="_blank" rel="noreferrer">{run.head_sha}</a>
                                        </td>
                                        <td className="codesm-decoupled-bundle-timestamp">
                                            {formatDate(run.started_at || run.created_at)}
                                        </td>
                                        <td>{run.duration ?? (run.status === 'in_progress' ? '⟳ Running' : '—')}</td>
                                        <td>
                                            <a href={run.html_url} target="_blank" rel="noreferrer"
                                                className="codesm-decoupled-bundle-run-link" title="View on GitHub">↗</a>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}

            {localLog.length > 0 && (
                <>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px', marginTop: '24px' }}>
                        <h4 style={{ margin: '0' }}>WP Dispatch Log ({localLog.length} entries)</h4>
                        <Button
                            variant="secondary"
                            isDestructive
                            isBusy={clearing}
                            disabled={clearing}
                            onClick={() => setShowClearConfirm(true)}
                        >
                            {clearing ? 'Clearing…' : 'Clear Logs'}
                        </Button>
                    </div>
                    <details className="codesm-decoupled-bundle-local-log">
                        <summary>View Log Entries</summary>
                        <table className="codesm-decoupled-bundle-log-table">
                            <thead>
                                <tr>
                                    <th>Dispatched</th>
                                    <th>Workflow</th>
                                    <th>Trigger</th>
                                    <th>Result</th>
                                    <th>Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                {localLog.map((entry, idx) => (
                                    <tr key={idx} className={entry.dispatch_ok ? '' : 'codesm-decoupled-bundle-log-row--failed'}>
                                        <td className="codesm-decoupled-bundle-timestamp">
                                            {formatDate(entry.dispatched_at_utc || entry.dispatched_at)}
                                        </td>
                                        <td>{entry.workflow_id || '—'}</td>
                                        <td>
                                            <span className={`codesm-decoupled-bundle-trigger-badge codesm-decoupled-bundle-trigger--${entry.trigger}`}>
                                                {entry.trigger === 'manual' ? 'WP Manual' : entry.trigger === 'abilities' ? 'Abilities API' : 'WP Auto'}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={entry.dispatch_ok ? 'codesm-decoupled-bundle-ok' : 'codesm-decoupled-bundle-fail'}>
                                                {entry.dispatch_ok ? 'Dispatched' : 'Failed'}
                                            </span>
                                        </td>
                                        <td className="codesm-decoupled-bundle-log-error">{entry.error || '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </details>

                    {showClearConfirm && (
                        <Modal
                            title="Clear Build Logs"
                            onRequestClose={() => setShowClearConfirm(false)}
                            size="small"
                        >
                            <p>Are you sure you want to permanently delete all {localLog.length} build log entries? This cannot be undone.</p>
                            <p style={{ fontSize: '0.9em', color: '#666', fontStyle: 'italic' }}>
                              Once cleared, you will lose the local WordPress record of which builds were triggered manually (WP Manual) or automatically (WP Auto). GitHub Actions history will still show code pushes and workflow dispatches.
                            </p>
                            {clearError && (
                                <Notice status="error" isDismissible onDismiss={() => setClearError(null)}>
                                    {clearError}
                                </Notice>
                            )}
                            <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '16px' }}>
                                <Button variant="secondary" onClick={() => setShowClearConfirm(false)} disabled={clearing}>
                                    Cancel
                                </Button>
                                <Button
                                    variant="primary"
                                    isDestructive
                                    isBusy={clearing}
                                    onClick={() => clearLogs()}
                                >
                                    Yes, Clear Logs
                                </Button>
                            </div>
                        </Modal>
                    )}
                </>
            )}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// Main App
// ─────────────────────────────────────────────────────────────────────────────

function App() {
    const [settings, setSettings] = useState(initialSettings || {});
    const validTabIds = TABS.map(t => t.id);
    const initialTab  = validTabIds.includes(new URLSearchParams(window.location.search).get('tab'))
        ? new URLSearchParams(window.location.search).get('tab')
        : 'site';
    const [activeTab, setActiveTab] = useState(initialTab);

    const switchTab = (id) => {
        setActiveTab(id);
        setTokenEditing(false);
        setTokenInput('');
        setShowTokenConfirm(false);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', id);
        window.history.replaceState(null, '', url);
    };
    const [isSaving, setIsSaving]   = useState(false);
    const [notice, setNotice]       = useState(null);

    // GitHub data (Build tab)
    const [workflows, setWorkflows]         = useState([]);
    const [branches, setBranches]           = useState([]);
    const [ghLoading, setGhLoading]         = useState(false);
    const [ghError, setGhError]             = useState(null);
    const [ghLoaded, setGhLoaded]           = useState(false);
    const [manualWorkflowId, setManualWorkflowId]         = useState('');
    const [manualRef, setManualRef]                       = useState('');
    const [isTriggering, setIsTriggering]                 = useState(false);
    const [showTriggerConfirm, setShowTriggerConfirm]     = useState(false);

    // Update checking (Updates tab)
    const [latestStable, setLatestStable]   = useState(null);
    const [latestPrerelease, setLatestPrerelease] = useState(null);
    const [updateChecking, setUpdateChecking]     = useState(false);
    const [updateError, setUpdateError]           = useState(null);

    // Token management
    const [tokenEditing, setTokenEditing]         = useState(false);
    const [showTokenConfirm, setShowTokenConfirm] = useState(false);
    const [tokenInput, setTokenInput]             = useState('');

    const showNotice = (status, message) => {
        setNotice({ status, message });
        setTimeout(() => setNotice(null), 5000);
    };

    // ── Nested state updaters ───────────────────────────────────────────────

    const mutateArray = (path, action) => setSettings(p => {
        const keys = path.split('.');
        const root = { ...p };
        let target = root;
        for (let i = 0; i < keys.length - 1; i++) target = target[keys[i]] = { ...target[keys[i]] };
        target[keys[keys.length - 1]] = action(target[keys[keys.length - 1]]);
        return root;
    });

    const setSite    = (key) => (val) => setSettings(p => ({ ...p, site:    { ...p.site,    [key]: val } }));
    const addEmail    = ()         => mutateArray('contact.emails', a => [...(a || []), { label: '', address: '' }]);
    const updateEmail = (i, patch) => mutateArray('contact.emails', a => { const e = [...(a || [])]; e[i] = { ...e[i], ...patch }; return e; });
    const removeEmail = (i)        => mutateArray('contact.emails', a => (a || []).filter((_, idx) => idx !== i));

    const addLocation    = ()         => mutateArray('contact.locations', a => [...(a || []), { label: '', address: { street: '', city: '', state: '', zip: '', country: '' }, coordinates: { lat: '', lng: '' } }]);
    const updateLocation = (i, patch) => mutateArray('contact.locations', a => { const l = [...(a || [])]; l[i] = { ...l[i], ...patch }; return l; });
    const removeLocation = (i)        => mutateArray('contact.locations', a => (a || []).filter((_, idx) => idx !== i));
    const addSocialEntry    = ()         => mutateArray('social', a => [...(a || []), { platform: '', url: '' }]);
    const updateSocialEntry = (i, patch) => mutateArray('social', a => { const s = [...(a || [])]; s[i] = { ...s[i], ...patch }; return s; });
    const removeSocialEntry = (i)        => mutateArray('social', a => (a || []).filter((_, idx) => idx !== i));
    const setGtm     = (key) => (val) => setSettings(p => ({ ...p, gtm:     { ...p.gtm,     [key]: val } }));
    const setBuild   = (key) => (val) => setSettings(p => ({ ...p, build:   { ...p.build,   [key]: val } }));
    const setPlugin  = (key) => (val) => setSettings(p => ({ ...p, plugin:  { ...p.plugin,  [key]: val } }));
    const setMaintenance = (key) => (val) => setSettings(p => ({ ...p, maintenance: { ...p.maintenance, [key]: val } }));

    const addPhone = () => mutateArray('contact.phones', a => [...(a || []), { label: '', number: '' }]);
    const updatePhone = (i, updated) => mutateArray('contact.phones', a => { const phones = [...(a || [])]; phones[i] = updated; return phones; });
    const removePhone = (i) => mutateArray('contact.phones', a => (a || []).filter((_, idx) => idx !== i));

    const addPageScript = () => mutateArray('scripts.pages', a => [...(a || []), { url_pattern: '', json_ld: '', header: '', body_start: '', body_end: '' }]);
    const updatePageScript = (i, updated) => mutateArray('scripts.pages', a => { const pages = [...(a || [])]; pages[i] = updated; return pages; });
    const removePageScript = (i) => mutateArray('scripts.pages', a => (a || []).filter((_, idx) => idx !== i));

    // ── Auto-build targets ─────────────────────────────────────────────────

    const addAutoTarget    = ()         => setBuild('auto_targets')([...(settings.build?.auto_targets || []), { ref: '', workflows: [] }]);
    const removeAutoTarget = (i)        => setBuild('auto_targets')((settings.build?.auto_targets || []).filter((_, idx) => idx !== i));
    const updateAutoTarget = (i, patch) => {
        const targets = [...(settings.build?.auto_targets || [])];
        targets[i] = { ...targets[i], ...patch };
        setBuild('auto_targets')(targets);
    };
    const toggleTargetWorkflow = (i, wfId) => {
        const targets  = [...(settings.build?.auto_targets || [])];
        const current  = targets[i]?.workflows || [];
        const updated  = current.includes(wfId) ? current.filter(x => x !== wfId) : [...current, wfId];
        targets[i] = { ...targets[i], workflows: updated };
        setBuild('auto_targets')(targets);
    };

    // ── GitHub data loader ─────────────────────────────────────────────────

    const loadGithubData = async () => {
        setGhLoading(true);
        setGhError(null);
        try {
            const [wfResult, brResult] = await Promise.all([
                apiFetch({ url: `${restUrl}/workflows` }),
                apiFetch({ url: `${restUrl}/branches` }),
            ]);
            if (wfResult.error) throw new Error(wfResult.error);
            if (brResult.error) throw new Error(brResult.error);

            const wfs = wfResult.workflows || [];
            const brs = brResult.branches  || [];
            setWorkflows(wfs);
            setBranches(brs);
            setGhLoaded(true);

            if (wfs.length && !manualWorkflowId) setManualWorkflowId(String(wfs[0].id));
            const defBranch = brs.find(b => b.default)?.name || 'main';
            if (!manualRef) setManualRef(defBranch);
        } catch (err) {
            setGhError(err.message || 'Failed to load GitHub data.');
        } finally {
            setGhLoading(false);
        }
    };

    // Auto-load GitHub data when the Build tab opens and credentials are present.
    useEffect(() => {
        if (activeTab === 'build' && build.github_token && build.github_repo && !ghLoaded && !ghLoading) {
            loadGithubData();
        }
    }, [activeTab]);

    const checkForUpdates = async () => {
        setUpdateChecking(true);
        setUpdateError(null);
        setLatestStable(null);
        setLatestPrerelease(null);
        try {
            const result = await apiFetch({ url: `${restUrl}/update-info` });
            if (result.error) {
                setUpdateError(result.error);
            } else {
                if (result.stable) setLatestStable(result.stable);
                if (result.prerelease) setLatestPrerelease(result.prerelease);
            }
        } catch (err) {
            setUpdateError(err.message || 'Failed to check for updates.');
        } finally {
            setUpdateChecking(false);
        }
    };

    // ── Save settings ──────────────────────────────────────────────────────

    const handleSave = async () => {
        setIsSaving(true);
        try {
            let dataToSave = { ...settings };

            // Only send token if explicitly edited
            if (tokenEditing && tokenInput) {
                dataToSave = {
                    ...dataToSave,
                    build: {
                        ...dataToSave.build,
                        github_token: tokenInput,
                    },
                };
            } else if (!tokenEditing) {
                // Remove token from payload if not editing (keeps existing value in DB)
                const { github_token, ...buildWithoutToken } = dataToSave.build;
                dataToSave = {
                    ...dataToSave,
                    build: buildWithoutToken,
                };
            }

            if (ghLoaded && workflows.length > 0) {
                const validIds = new Set(workflows.map(wf => String(wf.id)));
                dataToSave = {
                    ...dataToSave,
                    build: {
                        ...dataToSave.build,
                        auto_targets: (dataToSave.build?.auto_targets || []).map(target => ({
                            ...target,
                            workflows: (target.workflows || []).filter(id => validIds.has(id)),
                        })),
                    },
                };
            }
            const result = await apiFetch({ url: `${restUrl}/settings`, method: 'POST', data: dataToSave });
            if (result.success) {
                setSettings(result.settings);
                setTokenEditing(false);
                setTokenInput('');
                showNotice('success', i18n?.settingsSaved || 'Settings saved.');
            }
        } catch (err) {
            showNotice('error', err.message || i18n?.saveError || 'Failed to save settings.');
        } finally {
            setIsSaving(false);
        }
    };

    // ── Manual build trigger ───────────────────────────────────────────────

    const handleTriggerBuild = async () => {
        if (!manualWorkflowId) return;
        setIsTriggering(true);
        try {
            const result = await apiFetch({
                url: `${restUrl}/trigger-build`,
                method: 'POST',
                data: { workflow_id: manualWorkflowId, ref: manualRef },
            });
            if (result.success) showNotice('success', i18n?.buildSuccess || 'Build triggered successfully.');
        } catch (err) {
            showNotice('error', err.message || i18n?.buildError || 'Failed to trigger build.');
        } finally {
            setIsTriggering(false);
        }
    };

    // ── Derived values ─────────────────────────────────────────────────────

    const site = settings.site        || {};
    const contact = settings.contact     || {};
    const gtm = settings.gtm         || {};
    const scripts = settings.scripts     || {};
    const build = settings.build       || {};
    const plugin = settings.plugin      || {};
    const maintenance = settings.maintenance || {};

    const phones    = Array.isArray(contact.phones)    ? contact.phones    : [];
    const emails    = Array.isArray(contact.emails)    ? contact.emails    : [];
    const locations = Array.isArray(contact.locations) ? contact.locations : [];
    const autoTargets = Array.isArray(build.auto_targets) ? build.auto_targets : [];
    const socialEntries = Array.isArray(settings.social) ? settings.social : [];

    const branchOptions = branches.length > 0
        ? branches.map(b => ({ label: b.default ? `${b.name} (default)` : b.name, value: b.name }))
        : [];

    const workflowOptions = workflows.map(wf => ({
        label: wf.name || `Workflow ${wf.id}`,
        value: String(wf.id),
    }));

    // ── Render ─────────────────────────────────────────────────────────────

    return (
        <div className="codesm-decoupled-bundle-admin">

            {notice && (
                <Notice status={notice.status} onRemove={() => setNotice(null)} isDismissible>
                    {notice.message}
                </Notice>
            )}

            {/* ── Tab navigation ─────────────────────────────────────────── */}
            <div className="codesm-decoupled-bundle-tabs">
                <nav className="codesm-decoupled-bundle-tabs__nav" role="tablist">
                    {TABS.map(tab => (
                        <button
                            key={tab.id}
                            role="tab"
                            aria-selected={activeTab === tab.id}
                            className={`codesm-decoupled-bundle-tabs__tab${activeTab === tab.id ? ' is-active' : ''}`}
                            onClick={() => switchTab(tab.id)}
                        >
                            {tab.label}
                        </button>
                    ))}
                </nav>

                <div className="codesm-decoupled-bundle-tabs__panel">

                    {/* ════════════════ SITE ════════════════ */}
                    {activeTab === 'site' && (
                        <div className="codesm-decoupled-bundle-tab-content">
                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Identity</h3>
                                <div className="codesm-decoupled-bundle-field">
                                    <TextControl
                                        label="Site Title"
                                        value={site.title || ''}
                                        onChange={setSite('title')}
                                        __nextHasNoMarginBottom
                                    />
                                </div>
                                <div className="codesm-decoupled-bundle-field">
                                    <TextareaControl
                                        label="Site Description"
                                        value={site.description || ''}
                                        onChange={setSite('description')}
                                        rows={3}
                                        __nextHasNoMarginBottom
                                    />
                                </div>
                            </div>

                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Title Format</h3>
                                <p className="codesm-decoupled-bundle-panel-desc">
                                    Controls how the <code>&lt;title&gt;</code> tag is constructed in your Astro layout.
                                    Use <code>%title%</code>, <code>%site%</code>, and <code>%sep%</code> as tokens.
                                </p>
                                <div className="codesm-decoupled-bundle-field-row">
                                    <div className="codesm-decoupled-bundle-field codesm-decoupled-bundle-field--grow">
                                        <TextControl
                                            label="Title Format"
                                            value={site.title_format || '%title% %sep% %site%'}
                                            onChange={setSite('title_format')}
                                            placeholder="%title% %sep% %site%"
                                            __nextHasNoMarginBottom
                                        />
                                    </div>
                                    <div className="codesm-decoupled-bundle-field codesm-decoupled-bundle-field--narrow">
                                        <SelectControl
                                            label="Separator"
                                            value={site.title_separator || '|'}
                                            options={[
                                                { label: '| (Pipe)',    value: '|' },
                                                { label: '- (Hyphen)',  value: '-' },
                                                { label: '– (En Dash)', value: '–' },
                                                { label: '· (Middot)',  value: '·' },
                                                { label: '• (Bullet)',  value: '•' },
                                                { label: '/ (Slash)',   value: '/' },
                                                { label: ': (Colon)',   value: ':' },
                                            ]}
                                            onChange={setSite('title_separator')}
                                            __nextHasNoMarginBottom
                                        />
                                    </div>
                                </div>
                                <p className="codesm-decoupled-bundle-hint">
                                    Preview: <strong>{
                                        (site.title_format || '%title% %sep% %site%')
                                            .replace('%title%', 'About Us')
                                            .replace('%sep%', site.title_separator || '|')
                                            .replace('%site%', site.title || 'My Site')
                                    }</strong>
                                </p>
                            </div>
                        </div>
                    )}

                    {/* ════════════════ CONTACT ════════════════ */}
                    {activeTab === 'contact' && (
                        <div className="codesm-decoupled-bundle-tab-content">

                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Phone Numbers</h3>
                                {phones.map((phone, idx) => (
                                    <div key={idx} className="codesm-decoupled-bundle-phone-row">
                                        <TextControl
                                            label="Label"
                                            value={phone.label || ''}
                                            onChange={(v) => updatePhone(idx, { ...phone, label: v })}
                                            placeholder="Main, Sales, Support…"
                                            className="codesm-decoupled-bundle-phone-row__label"
                                            __nextHasNoMarginBottom
                                        />
                                        <TextControl
                                            label="Number"
                                            value={phone.number || ''}
                                            onChange={(v) => updatePhone(idx, { ...phone, number: v })}
                                            placeholder="+1 (555) 000-0000"
                                            type="tel"
                                            className="codesm-decoupled-bundle-phone-row__number"
                                            __nextHasNoMarginBottom
                                        />
                                        <Button variant="tertiary" isDestructive children={<TrashIcon />} onClick={() => removePhone(idx)} label="Remove" />
                                    </div>
                                ))}
                                <Button variant="secondary" onClick={addPhone}>+ Add Phone</Button>
                            </div>

                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Email Addresses</h3>
                                {emails.map((email, idx) => (
                                    <div key={idx} className="codesm-decoupled-bundle-phone-row">
                                        <TextControl
                                            label="Label"
                                            value={email.label || ''}
                                            onChange={(v) => updateEmail(idx, { label: v })}
                                            placeholder="Main, Support, Sales…"
                                            className="codesm-decoupled-bundle-phone-row__label"
                                            __nextHasNoMarginBottom
                                        />
                                        <TextControl
                                            label="Email"
                                            value={email.address || ''}
                                            onChange={(v) => updateEmail(idx, { address: v })}
                                            type="email"
                                            placeholder="hello@yoursite.com"
                                            className="codesm-decoupled-bundle-phone-row__number"
                                            __nextHasNoMarginBottom
                                        />
                                        <Button variant="tertiary" isDestructive children={<TrashIcon />} onClick={() => removeEmail(idx)} label="Remove" />
                                    </div>
                                ))}
                                <Button variant="secondary" onClick={addEmail}>+ Add Email</Button>
                            </div>

                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Locations</h3>
                                {locations.map((loc, idx) => (
                                    <LocationRow key={idx} loc={loc} index={idx} onChange={updateLocation} onRemove={removeLocation} />
                                ))}
                                <Button variant="secondary" onClick={addLocation}>+ Add Location</Button>
                            </div>

                        </div>
                    )}

                    {/* ════════════════ SOCIAL ════════════════ */}
                    {activeTab === 'social' && (
                        <div className="codesm-decoupled-bundle-tab-content">
                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Social Links</h3>
                                {socialEntries.map((entry, idx) => {
                                    const network = SOCIAL_NETWORKS.find(n => n.key === entry.platform);
                                    return (
                                        <div key={idx} className="codesm-decoupled-bundle-phone-row">
                                            <SelectControl
                                                label="Platform"
                                                value={entry.platform || ''}
                                                options={[{ label: '— Select —', value: '' }, ...SOCIAL_NETWORKS.map(n => ({ label: n.label, value: n.key }))]}
                                                onChange={(v) => updateSocialEntry(idx, { platform: v })}
                                                className="codesm-decoupled-bundle-phone-row__label"
                                                __nextHasNoMarginBottom
                                            />
                                            <TextControl
                                                label="URL"
                                                value={entry.url || ''}
                                                onChange={(v) => updateSocialEntry(idx, { url: v })}
                                                type="url"
                                                placeholder={network?.placeholder || 'https://'}
                                                className="codesm-decoupled-bundle-phone-row__number"
                                                __nextHasNoMarginBottom
                                            />
                                            <Button variant="tertiary" isDestructive children={<TrashIcon />} onClick={() => removeSocialEntry(idx)} label="Remove" />
                                        </div>
                                    );
                                })}
                                <Button variant="secondary" onClick={addSocialEntry}>+ Add Entry</Button>
                            </div>
                        </div>
                    )}

                    {/* ════════════════ SCRIPTS ════════════════ */}
                    {activeTab === 'scripts' && (
                        <div className="codesm-decoupled-bundle-tab-content">

                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Google Tag Manager</h3>
                                <div className="codesm-decoupled-bundle-field codesm-decoupled-bundle-field--narrow">
                                    <TextControl
                                        label="GTM Container ID"
                                        value={gtm.id || ''}
                                        onChange={setGtm('id')}
                                        placeholder="GTM-XXXXXXX"
                                        help="Your Astro layout reads this from the REST API and injects the GTM snippets."
                                        __nextHasNoMarginBottom
                                    />
                                </div>
                            </div>

                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Global Script Injection</h3>
                                <p className="codesm-decoupled-bundle-panel-desc">
                                    Injected on <strong>every page</strong>. Include full tags (e.g. <code>&lt;script&gt;…&lt;/script&gt;</code>).
                                </p>
                                <div className="codesm-decoupled-bundle-field">
                                    <TextareaControl
                                        label="Global JSON-LD"
                                        help="Sitewide structured data — without wrapping <script> tags. Applied on every page."
                                        value={scripts.json_ld || ''}
                                        onChange={(val) => setSettings(p => ({ ...p, scripts: { ...p.scripts, json_ld: val } }))}
                                        rows={6}
                                        className="codesm-decoupled-bundle-code-field"
                                        __nextHasNoMarginBottom
                                    />
                                </div>
                                <ScriptFields
                                    values={{ header: scripts.header || '', body_start: scripts.body_start || '', body_end: scripts.body_end || '' }}
                                    onChange={(updated) => setSettings(p => ({ ...p, scripts: { ...p.scripts, ...updated } }))}
                                />
                            </div>

                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Per-page Script Injection</h3>
                                <p className="codesm-decoupled-bundle-panel-desc">
                                    Scripts and JSON-LD injected only on specific pages. Use exact paths (<code>/about</code>) or wildcard suffixes (<code>/blog/*</code>).
                                </p>
                                {(scripts.pages || []).map((entry, idx) => (
                                    <PageScriptRow
                                        key={idx}
                                        entry={entry}
                                        index={idx}
                                        onChange={updatePageScript}
                                        onRemove={removePageScript}
                                    />
                                ))}
                                <Button variant="secondary" onClick={addPageScript} className="codesm-decoupled-bundle-add-page-btn">
                                    + Add Page Rule
                                </Button>
                            </div>

                        </div>
                    )}

                    {/* ════════════════ BUILD ════════════════ */}
                    {activeTab === 'build' && (
                        <div className="codesm-decoupled-bundle-tab-content">

                            {/* Credentials */}
                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">GitHub Credentials</h3>
                                <div className="codesm-decoupled-bundle-field-row">
                                    <div className="codesm-decoupled-bundle-field codesm-decoupled-bundle-field--grow">
                                        <TextControl
                                            label="Repository"
                                            value={build.github_repo || ''}
                                            onChange={setBuild('github_repo')}
                                            placeholder="owner/repository"
                                            help="Format: owner/repo"
                                            __nextHasNoMarginBottom
                                        />
                                    </div>
                                    <div className="codesm-decoupled-bundle-field codesm-decoupled-bundle-field--grow">
                                        <TextControl
                                            label="Personal Access Token"
                                            value={tokenEditing ? tokenInput : (build.github_token ? '••••••••••••••••' : '')}
                                            onChange={tokenEditing ? setTokenInput : () => {}}
                                            onMouseDown={() => {
                                                if (build.github_token && !tokenEditing) {
                                                    setShowTokenConfirm(true);
                                                }
                                            }}
                                            type="password"
                                            readOnly={!tokenEditing}
                                            placeholder={tokenEditing ? 'ghp_xxxxxxxxxxxxxxxxxxxx' : '••••••••••••••••'}
                                            help={
                                                tokenEditing
                                                    ? 'Enter your new GitHub Personal Access Token (fine-grained or classic with repo scope).'
                                                    : build.github_token
                                                    ? (<><strong>Token is set.</strong> Click to change it.</>)
                                                    : 'Fine-grained: Actions (read/write) + Contents (read). Classic: repo scope.'
                                            }
                                            __nextHasNoMarginBottom
                                        />
                                    </div>

                                    {showTokenConfirm && build.github_token && (
                                        <Modal
                                            title="Change GitHub Token?"
                                            onRequestClose={() => setShowTokenConfirm(false)}
                                            size="small"
                                        >
                                            <p>A GitHub Personal Access Token is already set. Do you want to replace it with a new one?</p>
                                            <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '16px' }}>
                                                <Button variant="secondary" onClick={() => setShowTokenConfirm(false)}>
                                                    Keep Current Token
                                                </Button>
                                                <Button
                                                    variant="primary"
                                                    onClick={() => {
                                                        setShowTokenConfirm(false);
                                                        setTokenEditing(true);
                                                    }}
                                                >
                                                    Change Token
                                                </Button>
                                            </div>
                                        </Modal>
                                    )}
                                </div>
                                <div className="codesm-decoupled-bundle-creds-actions">
                                    <Button
                                        variant="secondary"
                                        onClick={loadGithubData}
                                        isBusy={ghLoading}
                                        disabled={ghLoading || !build.github_repo || !build.github_token}
                                    >
                                        {ghLoaded ? 'Reload Workflows & Branches' : 'Load Workflows & Branches'}
                                    </Button>
                                    {ghError && <p className="codesm-decoupled-bundle-gh-error">{ghError}</p>}
                                </div>
                            </div>

                            {/* Auto-Build */}
                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Auto-Build</h3>
                                <div className="codesm-decoupled-bundle-field">
                                    <ToggleControl
                                        label="Enable auto-build on content changes"
                                        help="Schedules a rebuild whenever a post/page is published or trashed. Uses a debounce window to batch rapid edits."
                                        checked={!!build.auto_enabled}
                                        onChange={setBuild('auto_enabled')}
                                        __nextHasNoMarginBottom
                                    />
                                </div>

                                {build.auto_enabled && (
                                    <>
                                        <div className="codesm-decoupled-bundle-field-row">
                                            <div className="codesm-decoupled-bundle-field codesm-decoupled-bundle-field--narrow">
                                                <TextControl
                                                    label="Debounce delay (minutes)"
                                                    value={String(build.debounce_minutes ?? 5)}
                                                    onChange={(v) => setBuild('debounce_minutes')(Math.max(1, parseInt(v, 10) || 5))}
                                                    type="number"
                                                    min="1"
                                                    max="60"
                                                    help="Build fires this many minutes after the last change."
                                                    __nextHasNoMarginBottom
                                                />
                                            </div>
                                        </div>

                                        <div className="codesm-decoupled-bundle-section__sub">
                                            <p className="codesm-decoupled-bundle-section__sub-label">Branch targets:</p>
                                            {autoTargets.map((target, ti) => (
                                                <div key={ti} className="codesm-decoupled-bundle-target-card">
                                                    <div className="codesm-decoupled-bundle-target-card__header">
                                                        {branches.length > 0 ? (
                                                            <SelectControl
                                                                label="Branch"
                                                                value={target.ref || ''}
                                                                options={[{ label: '— Select —', value: '' }, ...branchOptions]}
                                                                onChange={(v) => updateAutoTarget(ti, { ref: v })}
                                                                __nextHasNoMarginBottom
                                                            />
                                                        ) : (
                                                            <TextControl
                                                                label="Branch"
                                                                value={target.ref || ''}
                                                                onChange={(v) => updateAutoTarget(ti, { ref: v })}
                                                                placeholder="production"
                                                                __nextHasNoMarginBottom
                                                            />
                                                        )}
                                                        <Button variant="tertiary" isDestructive children={<TrashIcon />} onClick={() => removeAutoTarget(ti)} label="Remove" />
                                                    </div>
                                                    <div className="codesm-decoupled-bundle-target-card__workflows">
                                                        <p className="codesm-decoupled-bundle-section__sub-label">Workflows:</p>
                                                        {ghLoaded && workflows.length > 0 ? (
                                                            workflows.map(wf => (
                                                                <CheckboxControl
                                                                    key={wf.id}
                                                                    label={wf.name}
                                                                    help={wf.filename}
                                                                    checked={(target.workflows || []).includes(String(wf.id))}
                                                                    onChange={() => toggleTargetWorkflow(ti, String(wf.id))}
                                                                    __nextHasNoMarginBottom
                                                                />
                                                            ))
                                                        ) : (
                                                            <p className="codesm-decoupled-bundle-hint">
                                                                {ghLoaded ? 'No workflows found.' : 'Load workflows above to pick.'}
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                            <Button variant="secondary" onClick={addAutoTarget}>+ Add Branch Target</Button>
                                        </div>
                                    </>
                                )}
                            </div>

                            {/* Manual Trigger */}
                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Manual Build Trigger</h3>
                                <p className="codesm-decoupled-bundle-panel-desc">
                                    Immediately dispatch a workflow_dispatch event to GitHub Actions.
                                </p>
                                {!ghLoaded ? (
                                    <p className="codesm-decoupled-bundle-hint">
                                        Enter your credentials above and click "Load Workflows & Branches" to enable manual triggering.
                                    </p>
                                ) : (
                                    <>
                                        <div className="codesm-decoupled-bundle-field-row">
                                            <div className="codesm-decoupled-bundle-field codesm-decoupled-bundle-field--grow">
                                                <SelectControl
                                                    label="Workflow"
                                                    value={manualWorkflowId}
                                                    options={workflowOptions}
                                                    onChange={setManualWorkflowId}
                                                    __nextHasNoMarginBottom
                                                />
                                            </div>
                                            <div className="codesm-decoupled-bundle-field codesm-decoupled-bundle-field--grow">
                                                <SelectControl
                                                    label="Branch"
                                                    value={manualRef}
                                                    options={branchOptions}
                                                    onChange={setManualRef}
                                                    __nextHasNoMarginBottom
                                                />
                                            </div>
                                        </div>
                                        <Button
                                            variant="primary"
                                            onClick={() => setShowTriggerConfirm(true)}
                                            isBusy={isTriggering}
                                            disabled={isTriggering || !manualWorkflowId}
                                        >
                                            {isTriggering ? 'Triggering…' : 'Trigger Build Now'}
                                        </Button>

                                        {showTriggerConfirm && (
                                            <Modal
                                                title="Trigger Build"
                                                onRequestClose={() => setShowTriggerConfirm(false)}
                                                size="small"
                                            >
                                                <p>
                                                    Dispatch <strong>{workflowOptions.find(w => w.value === manualWorkflowId)?.label || manualWorkflowId}</strong> on branch <strong>{manualRef}</strong>?
                                                </p>
                                                <div style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '16px' }}>
                                                    <Button variant="secondary" onClick={() => setShowTriggerConfirm(false)}>
                                                        Cancel
                                                    </Button>
                                                    <Button
                                                        variant="primary"
                                                        onClick={() => { setShowTriggerConfirm(false); handleTriggerBuild(); }}
                                                    >
                                                        Trigger Build
                                                    </Button>
                                                </div>
                                            </Modal>
                                        )}
                                    </>
                                )}
                            </div>

                        </div>
                    )}

                    {/* ════════════════ PLUGIN UPDATES ════════════════ */}
                    {activeTab === 'plugin-updates' && (
                        <div className="codesm-decoupled-bundle-tab-content">
                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Plugin Updates</h3>
                                <p className="codesm-decoupled-bundle-panel-desc">
                                    Check for the latest stable and prerelease versions of the CODESM Decoupled Bundle plugin.
                                </p>

                                <div className="codesm-decoupled-bundle-field">
                                    <CheckboxControl
                                        label="Opt in for Prerelease Versions"
                                        help="When enabled, this plugin will auto-update to prerelease versions. This setting is saved."
                                        checked={!!plugin.prerelease_enabled}
                                        onChange={setPlugin('prerelease_enabled')}
                                        __nextHasNoMarginBottom
                                    />
                                </div>

                                <Button
                                    variant="primary"
                                    onClick={checkForUpdates}
                                    isBusy={updateChecking}
                                    disabled={updateChecking}
                                >
                                    {updateChecking ? 'Checking…' : 'Check for Updates'}
                                </Button>

                                {updateError && (
                                    <Notice status="error" isDismissible={false} style={{ marginTop: '16px' }}>
                                        {updateError}
                                    </Notice>
                                )}

                                {latestStable && (
                                    <div className="codesm-decoupled-bundle-version-card codesm-decoupled-bundle-version-card--stable">
                                        <div className="codesm-decoupled-bundle-version-card__header">
                                            <span className="codesm-decoupled-bundle-version-card__icon">✓</span>
                                            <span className="codesm-decoupled-bundle-version-card__label">Latest Stable Release</span>
                                        </div>
                                        <div className="codesm-decoupled-bundle-version-card__version">{latestStable.version}</div>
                                        {latestStable.url && (
                                            <a href={latestStable.url} target="_blank" rel="noreferrer" className="codesm-decoupled-bundle-version-card__link">
                                                View on GitHub →
                                            </a>
                                        )}
                                    </div>
                                )}

                                {plugin.prerelease_enabled && latestPrerelease && (
                                    <div className="codesm-decoupled-bundle-version-card codesm-decoupled-bundle-version-card--prerelease">
                                        <div className="codesm-decoupled-bundle-version-card__header">
                                            <span className="codesm-decoupled-bundle-version-card__icon">⚡</span>
                                            <span className="codesm-decoupled-bundle-version-card__label">Latest Prerelease</span>
                                        </div>
                                        <div className="codesm-decoupled-bundle-version-card__version">{latestPrerelease.version}</div>
                                        {latestPrerelease.url && (
                                            <a href={latestPrerelease.url} target="_blank" rel="noreferrer" className="codesm-decoupled-bundle-version-card__link">
                                                View on GitHub →
                                            </a>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ════════════════ MAINTENANCE ════════════════ */}
                    {activeTab === 'maintenance' && (
                        <div className="codesm-decoupled-bundle-tab-content">
                            <div className="codesm-decoupled-bundle-section">
                                <h3 className="codesm-decoupled-bundle-section__title">Maintenance Mode</h3>
                                <p className="codesm-decoupled-bundle-panel-desc">
                                    Enable maintenance mode to display a maintenance page during site updates. <strong>All times are in UTC</strong>.
                                </p>

                                <UtcClock />

                                <div className="codesm-decoupled-bundle-field">
                                    <ToggleControl
                                        label="Enable maintenance mode"
                                        checked={!!maintenance.enabled}
                                        onChange={setMaintenance('enabled')}
                                        __nextHasNoMarginBottom
                                    />
                                </div>

                                {maintenance.enabled && (
                                    <>
                                        <div className="codesm-decoupled-bundle-field-row">
                                            <div className="codesm-decoupled-bundle-field codesm-decoupled-bundle-field--grow">
                                                <TextControl
                                                    label="Start Time (UTC)"
                                                    type="datetime-local"
                                                    value={unixToUtcDateTime(maintenance.from || 0)}
                                                    onChange={(v) => setMaintenance('from')(utcDateTimeToUnix(v))}
                                                    help="Leave empty for immediate activation"
                                                    __nextHasNoMarginBottom
                                                />
                                            </div>
                                            <div className="codesm-decoupled-bundle-field codesm-decoupled-bundle-field--grow">
                                                <TextControl
                                                    label="End Time (UTC)"
                                                    type="datetime-local"
                                                    value={unixToUtcDateTime(maintenance.to || 0)}
                                                    onChange={(v) => setMaintenance('to')(utcDateTimeToUnix(v))}
                                                    help="Leave empty for open-ended maintenance"
                                                    __nextHasNoMarginBottom
                                                />
                                            </div>
                                        </div>

                                        <div className="codesm-decoupled-bundle-field">
                                            <p style={{ fontSize: '0.9em', color: '#666', margin: '8px 0' }}>
                                                <strong>Status:</strong> {
                                                    !maintenance.from ? 'Active immediately' :
                                                    new Date().getTime() / 1000 < maintenance.from ? 'Scheduled (not yet active)' :
                                                    !maintenance.to || new Date().getTime() / 1000 <= maintenance.to ? 'Currently active' :
                                                    'Expired'
                                                }
                                            </p>
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>
                    )}

                    {/* ════════════════ DEPLOYMENTS ════════════════ */}
                    {activeTab === 'deployments' && (
                        <div className="codesm-decoupled-bundle-tab-content">
                            <DeploymentHistory repo={build.github_repo || ''} onShowNotice={showNotice} />
                        </div>
                    )}

                </div>
            </div>

            {activeTab !== 'deployments' && (
                <div className="codesm-decoupled-bundle-admin__footer">
                    <Button variant="primary" onClick={handleSave} isBusy={isSaving} disabled={isSaving}>
                        {isSaving ? (i18n?.saving || 'Saving…') : (i18n?.saveSettings || 'Save Settings')}
                    </Button>
                </div>
            )}

        </div>
    );
}

const container = document.getElementById('codesm-decoupled-bundle-admin');
if (container) {
    createRoot(container).render(<App />);
}
