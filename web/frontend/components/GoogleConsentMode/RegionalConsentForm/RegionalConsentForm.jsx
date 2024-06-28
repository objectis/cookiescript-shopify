import React from 'react';
import { Field, ErrorMessage, FieldArray } from 'formik';
import ConsentField from "../ConsentField/index.js"

export default function RegionalConsentForm({ values }) {
    return (
        <FieldArray name="regional_consents">
            {({ push, remove }) => (
                <>
                    {values.regional_consents.map((consent, index) => (
                        <div key={index}>
                            <h3>Region {consent.region || 'New Region'}</h3>
                            <div>
                                <label htmlFor={`regional_consents[${index}].region`}>Region:</label>
                                <Field type="text" name={`regional_consents[${index}].region`} />
                                <ErrorMessage name={`regional_consents[${index}].region`} component="div" />
                            </div>
                            <ConsentField name={`regional_consents[${index}].ad_storage`} label="Ad Storage" value={consent.ad_storage} />
                            <ConsentField name={`regional_consents[${index}].analytics_storage`} label="Analytics Storage" value={consent.analytics_storage} />
                            <ConsentField name={`regional_consents[${index}].ad_user_data`} label="Ad User Data" value={consent.ad_user_data} />
                            <ConsentField name={`regional_consents[${index}].ad_personalization`} label="Ad Personalization" value={consent.ad_personalization} />
                            <ConsentField name={`regional_consents[${index}].functionality_storage`} label="Functionality Storage" value={consent.functionality_storage} />
                            <ConsentField name={`regional_consents[${index}].personalization_storage`} label="Personalization Storage" value={consent.personalization_storage} />
                            <ConsentField name={`regional_consents[${index}].security_storage`} label="Security Storage" value={consent.security_storage} />
                            <div>
                                <label htmlFor={`regional_consents[${index}].wait_for_update`}>Wait for Update:</label>
                                <Field type="text" name={`regional_consents[${index}].wait_for_update`} />
                                <ErrorMessage name={`regional_consents[${index}].wait_for_update`} component="div" />
                            </div>
                            <button type="button" onClick={() => remove(index)}>Remove Region</button>
                        </div>
                    ))}
                    <button
                        type="button"
                        onClick={() =>
                            push({
                                region: '',
                                ad_storage: 'denied',
                                analytics_storage: 'denied',
                                ad_user_data: 'denied',
                                ad_personalization: 'denied',
                                functionality_storage: 'denied',
                                personalization_storage: 'denied',
                                security_storage: 'denied',
                                wait_for_update: '500',
                            })
                        }
                    >
                        Add Region
                    </button>
                </>
            )}
        </FieldArray>
    )
}
