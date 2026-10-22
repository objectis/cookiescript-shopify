import React, { useState, useEffect } from 'react';
import { Formik, Form, ErrorMessage, Field, yupToFormErrors } from 'formik'
import * as Yup from 'yup';
import { useAuthenticatedFetch } from '../../hooks';
import GlobalConsentForm from './GlobalConsentForm';
import RegionalConsentForm from './RegionalConsentForm';
import Cookie from "../../assets/images/icons/cookie.svg";

const CONSENT_VALUES = ['granted', 'denied'];
const MAX_WAIT_FOR_UPDATE = 3600000;
const DEFAULT_ERROR_MESSAGE = 'Failed to save settings. Please try again.';

const consentValue = (label) => Yup.string()
  .required(`${label} is required`)
  .oneOf(CONSENT_VALUES, `${label} must be Granted or Denied`);

const waitForUpdateValue = () => Yup.string()
  .required('Wait for Update is required')
  .matches(/^\s*\d+\s*$/, 'Wait for Update must be a whole number of milliseconds')
  .test('max-wait-for-update', `Wait for Update can't be more than ${MAX_WAIT_FOR_UPDATE}`,
    (waitForUpdate) => !waitForUpdate || Number(waitForUpdate) <= MAX_WAIT_FOR_UPDATE);

async function getErrorMessage(response) {
  try {
    const data = await response.json();
    const firstDetail = data?.details && Object.values(data.details).flat()[0];

    if (typeof firstDetail === 'string') {
      return firstDetail;
    }

    if (typeof data?.error === 'string') {
      return data.error;
    }
  } catch (parseError) {
    // The response has no JSON body, fall back to the generic message
  }

  return DEFAULT_ERROR_MESSAGE;
}

const GoogleConsentMode = () => {
  const fetch = useAuthenticatedFetch();
  const [isLoading, setIsLoading] = useState(false)
  const [saveError, setSaveError] = useState(null)
  const [initialValues, setInitialValues] = useState({
    ad_storage: 'denied',
    analytics_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    functionality_storage: 'denied',
    personalization_storage: 'denied',
    security_storage: 'denied',
    wait_for_update: '500',
    regional_consents: []
  });

  useEffect(() => {
    const fetchStoredSettings = async () => {
      try {
        const response = await fetch('/api/get-stored-settings');
        const data = await response.json();
        if (response.ok && data?.global_consent) {
          setInitialValues(prevValues => ({
            ...prevValues,
            ...data.global_consent,
            wait_for_update: String(data.global_consent.wait_for_update ?? prevValues.wait_for_update),
            google_consent_enabled: Boolean(data.global_consent.google_consent_enabled),
            regional_consents: (data.regional_consents || []).map(consent => ({
              region: consent.region || '',
              ad_storage: consent.ad_storage || 'denied',
              analytics_storage: consent.analytics_storage || 'denied',
              ad_user_data: consent.ad_user_data || 'denied',
              ad_personalization: consent.ad_personalization || 'denied',
              functionality_storage: consent.functionality_storage || 'denied',
              personalization_storage: consent.personalization_storage || 'denied',
              security_storage: consent.security_storage || 'denied',
              wait_for_update: String(consent.wait_for_update ?? '500')
            }))
          }));
        } else {
          console.error('Error fetching stored settings:', data);
        }
      } catch (error) {
        console.error('Error:', error);
      }
    };
    fetchStoredSettings();
  }, []);

  const validationSchema = Yup.object({
    ad_storage: consentValue('Ad Storage'),
    analytics_storage: consentValue('Analytics Storage'),
    ad_user_data: consentValue('Ad User Data'),
    ad_personalization: consentValue('Ad Personalization'),
    functionality_storage: consentValue('Functionality Storage'),
    personalization_storage: consentValue('Personalization Storage'),
    security_storage: consentValue('Security Storage'),
    wait_for_update: waitForUpdateValue(),
    regional_consents: Yup.array().of(
      Yup.object({
        region: Yup.string()
          .required('Region is required')
          .matches(
            /^\s*([a-z]{2}(-[a-z0-9]{1,3})?\s*)(,\s*[a-z]{2}(-[a-z0-9]{1,3})?\s*)*$/i,
            'Format is incorrect'
          ),
        ad_storage: consentValue('Ad Storage'),
        analytics_storage: consentValue('Analytics Storage'),
        ad_user_data: consentValue('Ad User Data'),
        ad_personalization: consentValue('Ad Personalization'),
        functionality_storage: consentValue('Functionality Storage'),
        personalization_storage: consentValue('Personalization Storage'),
        security_storage: consentValue('Security Storage'),
        wait_for_update: waitForUpdateValue()
      })
    )
  });

  // The consent fields are hidden while Google Consent Mode is disabled, so they are only validated when enabled
  const validateForm = async (formValues) => {
    if (!formValues.google_consent_enabled) {
      return {};
    }

    try {
      await validationSchema.validate(formValues, { abortEarly: false });
      return {};
    } catch (validationError) {
      return yupToFormErrors(validationError);
    }
  };

  const addScriptTag = async () => {
    const scriptResponse = await fetch('/api/add-script-tag', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
    });

    if (!scriptResponse.ok) {
      throw new Error(await getErrorMessage(scriptResponse));
    }

    return scriptResponse.json();
  };

  const handleSubmit = async (values, { setSubmitting }) => {
    setSaveError(null)

    try {
      setIsLoading(true)
      const response = await fetch('/api/google-consent-mode', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(values)
      });

      if (!response.ok) {
        const errorMessage = await getErrorMessage(response);
        console.error('Error saving settings:', errorMessage);
        setSaveError(errorMessage);
        return;
      }

      await addScriptTag();
    } catch (error) {
      console.error('Error:', error);
      setSaveError(error?.message || DEFAULT_ERROR_MESSAGE);
    } finally {
      setIsLoading(false)
      setSubmitting(false);
    }
  };

  return (
    <Formik
      initialValues={initialValues}
      validate={validateForm}
      enableReinitialize
      onSubmit={handleSubmit}
    >
      {({ isSubmitting, values }) => (
        <Form>
          {isLoading && values.google_consent_enabled
            ? <div className="cookie-script__loading-cover ">
              <img className="rotating" src={Cookie} alt="Cookie" />
            </div>
            : null
          }
          <h2>Google Consent Mode</h2>
          <div className="google-consent-mode__controls">
            <div>
              <Field type="checkbox" name="google_consent_enabled" />
              <label htmlFor="google_consent_enabled"> Enable Google Consent Mode</label>
              <ErrorMessage className="error-message" name="google_consent_enabled" component="div" />
            </div>
            <button className={`btn btn--${!isLoading ? "primary" : "disabled"}`} type="submit" disabled={isSubmitting}>
              Save Settings
            </button>
          </div>
          {saveError && (
            <p className="google-consent-mode__status google-consent-mode__status--error">{saveError}</p>
          )}
          {values.google_consent_enabled && (
            <>
              <GlobalConsentForm values={values} />
              <RegionalConsentForm values={values} />
            </>
          )}
        </Form>
      )}
    </Formik>
  );
};

export default GoogleConsentMode;
