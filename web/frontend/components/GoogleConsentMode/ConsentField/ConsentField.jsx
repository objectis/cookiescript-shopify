import React from 'react'
import { Field, ErrorMessage } from 'formik'

export default function ConsentField({ name, label, value }) {
    return (
        <div>
            <label htmlFor={name}>{label}:</label>
            <Field as="select" name={name} value={value}>
                <option value="granted">Granted</option>
                <option value="denied">Denied</option>
            </Field>
            <ErrorMessage name={name} component="div" />
        </div>
    );
}
