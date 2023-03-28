import React from "react"
import {Formik, Field, Form, ErrorMessage} from "formik"
import * as Yup from "yup"
import ErrorIcon from "../../assets/images/icons/erorr-circle.svg"

export default function ScriptForm({handleSubmit, isLoading}) {

  const validationSchema = Yup.object().shape({
    script: Yup.string()
      .required('This field is required')
      .trim()
      .matches(/\/\/geo\.cookie-script\.com\/s\/[a-zA-Z0-9]{32}|\/\/cdn\.cookie-script\.com\/s\/[a-zA-Z0-9]{32}/ , 'Format is incorrect')
  })

  const initialValues = {
    script: '',
  }

  return (
    <>
      <div className="form-control">
        <div className="cookie-script__header">Add Cookie Script source URL</div>
        <Formik
          validationSchema={validationSchema}
          initialValues={initialValues}
          validateOnChange={false}
          validateOnBlur={false}
          onSubmit={(values, {resetForm}) => {
            handleSubmit(values)
            resetForm(initialValues)
          }}
        >
          {({errors}) => (
            <Form className="form-control__form">
              <Field className={`form-control__input ${errors.script ? 'form-control__input-error' : ''}`} id="script"
                     name="script" placeholder="//cdn.cookie-script.com/s/********************************.js"/>
              {errors.script
                ? <div className="form-control__error">
                  <img src={ErrorIcon} alt="Error Icon"/>
                  <ErrorMessage name="script" component="span"/>
                </div>
                : null}
              <button className="btn btn--primary" type="submit" disabled={!isLoading}>Add</button>
            </Form>
          )}
        </Formik>
      </div>
    </>
  )
}