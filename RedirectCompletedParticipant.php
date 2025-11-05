<?php

use LimeSurvey\PluginManager\PluginBase;

/**
 * RedirectCompletedParticipant
 *
 * Redirects already-completed token holders to a configurable URL template.
 */
class RedirectCompletedParticipant extends PluginBase
{
    protected $storage = 'DbStorage';
    protected static $name = 'RedirectCompletedParticipant';
    protected static $description = 'Redirect participants with completed tokens to a configured URL.';

    protected $settings = [
        'globalEnabled' => [
            'type' => 'boolean',
            'label' => 'Enable redirect for all surveys by default',
            'default' => 0,
        ],
        'defaultRedirectUrl' => [
            'type' => 'string',
            'label' => 'Default redirect URL template',
            'htmlOptions' => [
                'placeholder' => 'https://example.com/?token={TOKEN}'
            ],
            'default' => '',
        ],
        'defaultParseExpressions' => [
            'type' => 'boolean',
            'label' => 'Parse ExpressionScript placeholders in default template',
            'default' => 1,
        ],
    ];

    public function init()
    {
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
        $this->subscribe('onSurveyDenied');
    }

    public function beforeSurveySettings()
    {
        $event = $this->getEvent();
        $surveyId = (int) $event->get('survey');

        $event->set("surveysettings.{$this->id}", [
            'name' => get_class($this),
            'settings' => [
                'enabled' => [
                    'type' => 'boolean',
                    'label' => gT('Redirect completed tokens'),
                    'default' => 0,
                    'current' => $this->get('enabled', 'Survey', $surveyId),
                ],
                'redirectUrl' => [
                    'type' => 'string',
                    'label' => gT('Redirect URL template'),
                    'htmlOptions' => [
                        'placeholder' => 'https://example.com/?token={TOKEN}'
                    ],
                    'current' => $this->get('redirectUrl', 'Survey', $surveyId),
                ],
                'parseExpressions' => [
                    'type' => 'boolean',
                    'label' => gT('Parse ExpressionScript placeholders'),
                    'default' => 1,
                    'current' => $this->get('parseExpressions', 'Survey', $surveyId, 1),
                ],
                'skipIfEditable' => [
                    'type' => 'boolean',
                    'label' => gT('Skip redirect when responses are editable after completion'),
                    'default' => 1,
                    'current' => $this->get('skipIfEditable', 'Survey', $surveyId, 1),
                ],
            ],
        ]);
    }

    public function newSurveySettings()
    {
        $event = $this->getEvent();
        $surveyId = (int) $event->get('survey');
        foreach ((array) $event->get('settings') as $name => $value) {
            $this->set($name, $value, 'Survey', $surveyId);
        }
    }

    public function onSurveyDenied()
    {
        $event = $this->getEvent();
        if ($event->get('reason') !== 'invalidToken') {
            return;
        }

        $surveyId = (int) $event->get('surveyId');
        if (!$surveyId) {
            return;
        }

        $survey = Survey::model()->findByPk($surveyId);
        if (!$survey) {
            return;
        }

        if (!$this->isSurveyEnabled($surveyId)) {
            return;
        }

        if ($this->shouldSkipForEditableSurvey($surveyId, $survey)) {
            return;
        }

        $tokenValue = $this->getCurrentToken($surveyId);
        if (empty($tokenValue)) {
            return;
        }

        $token = Token::model($surveyId)->findByAttributes(['token' => $tokenValue]);
        if (!$token || $this->tokenIsIncomplete($token)) {
            return;
        }

        $response = $this->findMostRecentResponse($surveyId, $tokenValue);
        $redirectUrl = $this->buildRedirectUrl($survey, $token, $response);
        if (empty($redirectUrl)) {
            return;
        }

        App()->getController()->redirect($redirectUrl);
        Yii::app()->end();
    }

    protected function isGlobalEnabled()
    {
        return (bool) $this->get('globalEnabled', null, null, 0);
    }

    protected function isSurveyEnabled($surveyId)
    {
        $surveySetting = $this->get('enabled', 'Survey', $surveyId);
        if ($surveySetting === null) {
            return (bool) $this->get('globalEnabled', null, null, 0);
        }
        return (bool) $surveySetting;
    }

    protected function shouldSkipForEditableSurvey($surveyId, Survey $survey)
    {
        $skip = $this->get('skipIfEditable', 'Survey', $surveyId);
        if ($skip === null) {
            $skip = 1;
        }
        if (!$skip) {
            return false;
        }
        if ($survey->getIsAllowEditAfterCompletion()) {
            return true;
        }
        return false;
    }

    protected function tokenIsIncomplete(Token $token)
    {
        return $token->completed === 'N' || $token->completed === '' || $token->completed === null;
    }

    protected function getCurrentToken($surveyId)
    {
        $requestToken = Yii::app()->request->getParam('token');
        if (!empty($requestToken)) {
            return trim((string) $requestToken);
        }
        $sessionKey = 'survey_' . $surveyId;
        if (isset($_SESSION[$sessionKey]['token']) && $_SESSION[$sessionKey]['token'] !== '') {
            return trim((string) $_SESSION[$sessionKey]['token']);
        }
        return null;
    }

    protected function findMostRecentResponse($surveyId, $token)
    {
        $criteria = new CDbCriteria();
        $criteria->compare('token', $token);
        $criteria->order = 'id DESC';
        return Response::model($surveyId)->find($criteria);
    }

    protected function buildRedirectUrl(Survey $survey, Token $token, $response)
    {
        $surveyId = (int) $survey->sid;
        $template = $this->get('redirectUrl', 'Survey', $surveyId);
        if ($template === null || trim((string) $template) === '') {
            $template = $this->get('defaultRedirectUrl', null, null, '');
        }
        $template = trim((string) $template);
        if ($template === '') {
            return null;
        }

        $replacements = $this->buildReplacementData($survey, $token, $response);

        $parseExpressions = $this->get('parseExpressions', 'Survey', $surveyId);
        if ($parseExpressions === null) {
            $parseExpressions = $this->get('defaultParseExpressions', null, null, 1);
        }

        if ($parseExpressions) {
            $rendered = $this->processTemplateWithExpressions($template, $replacements);
        } else {
            $rendered = $this->simpleReplace($template, $replacements);
        }

        return $this->sanitizeRedirectUrl($rendered);
    }

    protected function buildReplacementData(Survey $survey, Token $token, $response)
    {
        $replacements = [
            'SID' => $survey->sid,
            'SURVEYID' => $survey->sid,
            'SURVEYNAME' => $survey->localizedTitle,
            'TOKEN' => $token->token,
        ];

        foreach ($token->attributes as $attribute => $value) {
            $key = strtoupper((string) $attribute);
            $replacements[$key] = $value;
            $replacements['TOKEN:' . $key] = $value;
        }

        if ($response) {
            Yii::app()->loadHelper('common');
            $language = $response->startlanguage ?: $survey->language;
            $fieldMap = createFieldMap($survey, 'short', false, false, $language);
            $attributes = $response->attributes;
            foreach ($fieldMap as $field) {
                if (empty($field['title'])) {
                    continue;
                }
                $fieldName = $field['fieldname'];
                if (!array_key_exists($fieldName, $attributes)) {
                    continue;
                }
                $value = $attributes[$fieldName];
                if ($value === '' || $value === null) {
                    continue;
                }
                $code = (string) $field['title'];
                $replacements[$code] = $value;
                $replacements[strtoupper($code)] = $value;
            }
        }

        return $replacements;
    }

    protected function processTemplateWithExpressions($template, array $replacements)
    {
        $normalized = preg_replace_callback('/\{TOKEN:([A-Z0-9_]+)\}/i', function ($matches) {
            return '{' . strtoupper($matches[1]) . '}';
        }, $template);

        return trim((string) LimeExpressionManager::ProcessString(
            $normalized,
            null,
            $replacements,
            3,
            1,
            false,
            false,
            true
        ));
    }

    protected function simpleReplace($template, array $replacements)
    {
        $search = [];
        $replace = [];
        foreach ($replacements as $key => $value) {
            $search[] = '{' . $key . '}';
            $replace[] = $value;
        }
        return str_replace($search, $replace, $template);
    }

    protected function sanitizeRedirectUrl($url)
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }
        if (stripos($trimmed, 'javascript:') === 0) {
            return null;
        }
        return $trimmed;
    }
}
