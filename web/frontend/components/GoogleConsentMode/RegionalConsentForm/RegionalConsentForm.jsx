import React from 'react';
import {Field, ErrorMessage, FieldArray} from 'formik';
import ConsentField from "../ConsentField/index.js"

export default function RegionalConsentForm({values}) {
  return (
    <FieldArray name="regional_consents">
      {({push, remove}) => (
        <>
          {values.regional_consents.length !== 0
            ? <div>
              <h2>Regional Settings</h2>
            </div>
            : null
          }
          {values.regional_consents.map((consent, index) => (
            <div key={index}  className="google-consent-mode__regions">
              <div className="google-consent-mode__region-controls">
                <h3>Region {consent.region || 'New Region'}</h3>
                <button className="btn btn--delete" type="button" onClick={() => remove(index)}>Remove Region</button>
              </div>
              <div className="google-consent-mode__region">
                <div className="google-consent-mode__input-box">
                  <div className="google-consent-mode__inputs">
                    <label htmlFor={`regional_consents[${index}].region`}>Region Code</label>
                    <Field type="text" name={`regional_consents[${index}].region`}/>
                    <ErrorMessage className="error-message" name={`regional_consents[${index}].region`} component="div"/>
                  </div>
                  <ConsentField name={`regional_consents[${index}].ad_storage`} label="Ad Storage"/>
                  <ConsentField name={`regional_consents[${index}].analytics_storage`} label="Analytics Storage"/>
                  <ConsentField name={`regional_consents[${index}].ad_user_data`} label="Ad User Data"/>
                  <ConsentField name={`regional_consents[${index}].ad_personalization`} label="Ad Personalization"/>
                </div>
                <div className="google-consent-mode__input-box">
                  <ConsentField name={`regional_consents[${index}].functionality_storage`} label="Functionality Storage"/>
                  <ConsentField name={`regional_consents[${index}].personalization_storage`}
                                label="Personalization Storage"/>
                  <ConsentField name={`regional_consents[${index}].security_storage`} label="Security Storage"/>
                  <div className="google-consent-mode__inputs">
                    <label htmlFor={`regional_consents[${index}].wait_for_update`}>Wait for Update:</label>
                    <Field type="text" name={`regional_consents[${index}].wait_for_update`}/>
                    <ErrorMessage className="error-message" name={`regional_consents[${index}].wait_for_update`} component="div"/>
                  </div>
                </div>

              </div>
              <div>
                <button
                  className="btn btn--secondary"
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
              </div>
            </div>
          ))}
        </>
      )}
    </FieldArray>
  )
}
