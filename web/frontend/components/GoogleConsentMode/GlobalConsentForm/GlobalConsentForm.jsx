import React from 'react'
import {ErrorMessage, Field} from 'formik'
import ConsentField from "../ConsentField/index.js"

export default function GlobalConsentForm() {
  return (
    <div className="google-consent-mode__global">
      <div className="google-consent-mode__input-box">
        <ConsentField name="ad_storage" label="Ad Storage"/>
        <ConsentField name="analytics_storage" label="Analytics Storage"/>
        <ConsentField name="ad_user_data" label="Ad User Data"/>
        <ConsentField name="ad_personalization" label="Ad Personalization"/>
      </div>
      <div className="google-consent-mode__input-box">
        <ConsentField name="functionality_storage" label="Functionality Storage"/>
        <ConsentField name="personalization_storage" label="Personalization Storage"/>
        <ConsentField name="security_storage" label="Security Storage"/>
        <div className="google-consent-mode__inputs">
          <label htmlFor="wait_for_update">Wait for Update:</label>
          <Field type="text" name="wait_for_update"/>
          <ErrorMessage className="error-message" name="wait_for_update" component="div"/>
        </div>
      </div>
    </div>
  )
}
