# Redirect Completed Participant

Redirect participants who try to access a LimeSurvey survey with a token that has already been marked completed. Instead of seeing the default "invitation already used" page, they are sent to a configurable URL template that can contain ExpressionScript placeholders.

## Features
- Hooks into the `onSurveyDenied` event, only affecting the `invalidToken` reason (completed tokens or exhausted uses).
- Global defaults for enablement, redirect URL template, and Expression parsing.
- Optional dedicated redirect URL for quota-locked tokens (`completed = Q`), with fallback to the normal completed-token URL when unset.
- Survey-level overrides per survey: enable/disable, custom template, quota-specific template, toggle for Expression parsing, and option to skip when responses are editable after completion.
- Token attributes (including `{TOKEN:ATTRIBUTE}`) and completed response values become available for placeholder replacement.
- Sanitizes the final URL before redirecting (`javascript:` etc. are ignored).

## Installation
## Install to plugins folder

```
cd /LimeSurveyFolder/plugins
```


```
git clone https://github.com/TonisOrmisson/limesurvey-redirect-completes.git  RedirectCompletedParticipant
```

In the Admin UI, go to **Configuration → Plugins**, find *RedirectCompletedParticipant*, and click **Activate**. 
Click **Settings** to configure:
   - **Enable redirect for all surveys by default**: when on, every survey inherits the redirect unless explicitly disabled.
   - **Default redirect URL template**: fallback template (e.g., `https://example.com/?token={TOKEN}`).
   - **Default quota redirect URL template**: optional dedicated fallback for quota-locked tokens (e.g., `https://example.com/quota?token={TOKEN}`).
   - **Parse ExpressionScript placeholders in default template**: toggle Expression Manager processing for the default template.

## Per-Survey Configuration
Inside a survey’s **Plugins** tab you’ll find the plugin’s block:
- **Redirect completed tokens**: enable/disable for that survey.
- **Redirect URL template**: survey-specific template; falls back to the global one if left empty.
- **Quota redirect URL template**: optional survey-specific redirect for quota-locked tokens; falls back to the global quota URL and then to the normal redirect URL if left empty.
- **Parse ExpressionScript placeholders**: overrides whether `{TOKEN}`, `{age}`, `{ATTRIBUTE_1}`, etc. are run through Expression Manager.
- **Skip redirect when responses are editable after completion**: default `Yes`; keeps the default edit-after-completion flow intact.

Templates support any Expression Manager variable available at denial time. Common placeholders:
- `{TOKEN}` – current token string.
- `{TOKEN:FIRSTNAME}` – token attribute.
- `{SID}` – survey ID.
- `{LANGUAGE}` – current entry language (`App()->language`).
- `{QCODE}` – response field codes (e.g., `{AGE}`) if a completed response exists.

Both the normal redirect URL and the quota redirect URL use the same replacement data and ExpressionScript parsing setting.

## Development
- Global/survey settings are stored via `PluginBase::set()` using the provided storage backend.
- URL rendering occurs in `RedirectCompletedParticipant::buildRedirectUrl()`; adjust there for additional sanitization or replacement behavior.
- AGENTS.md and plan.md are intentionally ignored from Git to keep internal planning notes out of the repo.

## License
See `LICENSE` in the repository root.
