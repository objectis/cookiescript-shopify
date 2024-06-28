import React from 'react';
import {Field, ErrorMessage} from 'formik';

export default function ConsentField({name, label}) {
  return (
    <div className="google-consent-mode__inputs">
      <label htmlFor={name}>{label}</label>
      <Field as="select" name={name}>
        <option value="granted">Granted</option>
        <option value="denied">Denied</option>
      </Field>
      <ErrorMessage className="error-message" name={name} component="div"/>
    </div>
  );
}
