import React, { useState, useEffect } from 'react';
import { Formik, Form } from 'formik';
import * as Yup from 'yup';
import { useAuthenticatedFetch } from '../../hooks';
import GlobalConsentForm from './GlobalConsentForm';
import RegionalConsentForm from './RegionalConsentForm';

const GoogleConsentMode = () => {
    const fetch = useAuthenticatedFetch();
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
                        ...data.global_consent.consent_settings,
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
                region: Yup.string().required('Region is required'),
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

    const handleSubmit = async (values, { setSubmitting }) => {
        try {
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

                const scriptResponse = await fetch('/api/add-script-tag', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                });

                const scriptData = await scriptResponse.json();

                if (scriptResponse.ok) {
                    console.log('Script tag added successfully:', scriptData);
                } else {
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
            {({ isSubmitting, values }) => (
                <Form>
                    <GlobalConsentForm values={values} />
                    <RegionalConsentForm values={values} />
                    <button type="submit" disabled={isSubmitting}>
                        Save Settings
                    </button>
                </Form>
            )}
        </Formik>
    );
};

export default GoogleConsentMode;
