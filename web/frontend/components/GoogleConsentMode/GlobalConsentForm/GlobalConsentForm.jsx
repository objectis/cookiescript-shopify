import React from 'react'
import { ErrorMessage, Field } from 'formik'
import ConsentField from "../ConsentField/index.js"

export default function GlobalConsentForm({ values }) {
    return (
        <>
            <ConsentField name="ad_storage" label="Ad Storage" value={values.ad_storage}/>
            <ConsentField name="analytics_storage" label="Analytics Storage"  value={values.analytics_storage}/>
            <ConsentField name="ad_user_data" label="Ad User Data" value={values.ad_user_data}/>
            <ConsentField name="ad_personalization" label="Ad Personalization" value={values.ad_personalization}/>
            <ConsentField name="functionality_storage" label="Functionality Storage" value={values.functionality_storage}/>
            <ConsentField name="personalization_storage" label="Personalization Storage" value={values.personalization_storage}/>
            <ConsentField name="security_storage" label="Security Storage" value={values.security_storage}/>
            <div>
                <label htmlFor="wait_for_update">Wait for Update:</label>
                <Field type="text" name="wait_for_update"/>
                <ErrorMessage name="wait_for_update" component="div"/>
            </div>
        </>
    )
}
