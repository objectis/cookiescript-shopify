import React, {useState, useEffect} from 'react';
import {Formik, Form, ErrorMessage, Field} from 'formik'
import * as Yup from 'yup';
import {useAuthenticatedFetch} from '../../hooks';
import GlobalConsentForm from './GlobalConsentForm';
import RegionalConsentForm from './RegionalConsentForm';
import Cookie from "../../assets/images/icons/cookie.svg";

const GoogleConsentMode = () => {
  const fetch = useAuthenticatedFetch();
  const [isLoading, setIsLoading] = useState(false)
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
        if (response.ok) {
          setInitialValues(prevValues => ({
            ...prevValues,
            ...data.global_consent,
            google_consent_enabled: data.global_consent.google_consent_enabled,
            regional_consents: data.regional_consents.map(consent => ({
              region: consent.region || '',
              ad_storage: consent.ad_storage || 'denied',
              analytics_storage: consent.analytics_storage || 'denied',
              ad_user_data: consent.ad_user_data || 'denied',
              ad_personalization: consent.ad_personalization || 'denied',
              functionality_storage: consent.functionality_storage || 'denied',
              personalization_storage: consent.personalization_storage || 'denied',
              security_storage: consent.security_storage || 'denied',
              wait_for_update: consent.wait_for_update || '500'
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
    ad_storage: Yup.string().required('Ad Storage is required'),
    analytics_storage: Yup.string().required('Analytics Storage is required'),
    ad_user_data: Yup.string().required('Ad User Data is required'),
    ad_personalization: Yup.string().required('Ad Personalization is required'),
    functionality_storage: Yup.string().required('Functionality Storage is required'),
    personalization_storage: Yup.string().required('Personalization Storage is required'),
    security_storage: Yup.string().required('Security Storage is required'),
    wait_for_update: Yup.string().required('Wait for Update is required'),
    regional_consents: Yup.array().of(
      Yup.object({
        region: Yup.string()
          .required('Region is required')
          .matches(
            /^\s*([a-z]{2}(-[a-z0-9]{1,3})?\s*)(,\s*[a-z]{2}(-[a-z0-9]{1,3})?\s*)*$/i,
            'Format is incorrect'
          ),
        ad_storage: Yup.string().required('Ad Storage is required'),
        analytics_storage: Yup.string().required('Analytics Storage is required'),
        ad_user_data: Yup.string().required('Ad User Data is required'),
        ad_personalization: Yup.string().required('Ad Personalization is required'),
        functionality_storage: Yup.string().required('Functionality Storage is required'),
        personalization_storage: Yup.string().required('Personalization Storage is required'),
        security_storage: Yup.string().required('Security Storage is required'),
        wait_for_update: Yup.string().required('Wait for Update is required')
      })
    )
  });

  const addScriptTag = async () => {
    const scriptResponse = await fetch('/api/add-script-tag', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
    });

    return scriptResponse.json();
  };

  const handleSubmit = async (values, {setSubmitting}) => {
    try {
      setIsLoading(true)

      const response = await fetch('/api/google-consent-mode', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(values)
      });

      const data = await response.json();

      if (response.ok) {
        console.log('Settings saved successfully');

        const scriptData = await addScriptTag();

        if (addScriptTag) {
          setIsLoading(false)
          await addScriptTag()
          console.log('Script tag updated successfully:', scriptData);
        } else {
          setIsLoading(false)
          console.error('Error adding script tag:', scriptData);
        }
      } else {
        console.error('Error saving settings:', data);
      }
    } catch (error) {
      console.error('Error:', error);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Formik
      initialValues={initialValues}
      validationSchema={validationSchema}
      enableReinitialize
      onSubmit={handleSubmit}
    >
      {({isSubmitting, values}) => (
        <Form>
          {isLoading && values.google_consent_enabled
            ? <div className="cookie-script__loading-cover ">
              <img className="rotating" src={Cookie} alt="Cookie"/>
            </div>
            : null
          }
          <div className="google-consent-mode__controls">
            <div>
              <Field type="checkbox" name="google_consent_enabled"/>
              <label htmlFor="google_consent_enabled"> Enable Google Consent Mode</label>
              <ErrorMessage className="error-message" name="google_consent_enabled" component="div"/>
            </div>
            <button className={`btn btn--${!isLoading ? "primary" : "disabled"}`} type="submit" disabled={isSubmitting}>
              Save Settings
            </button>
          </div>
          {values.google_consent_enabled && (
            <>
              <GlobalConsentForm values={values}/>
              <RegionalConsentForm values={values}/>
            </>
          )}
        </Form>
      )}
    </Formik>
  );
};

export default GoogleConsentMode;
