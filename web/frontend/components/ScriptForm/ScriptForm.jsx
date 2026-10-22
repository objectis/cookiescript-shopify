import React from "react"
import { Formik, Field, Form, ErrorMessage } from "formik"
import * as Yup from "yup"
import ErrorIcon from "../../assets/images/icons/erorr-circle.svg"

// Production (/s/) and staging (/staging/) CookieScript banner URLs, with or without the https: scheme
// (optionally followed by a query string such as ?region=eu used by geo-targeted banners)
const SCRIPT_URL_PATTERN = /(?:https?:)?\/\/(?:geo|cdn)\.cookie-script\.com\/(?:s|staging)\/[a-zA-Z0-9]{32}\.js(?:\?[a-zA-Z0-9=&_.,\-]*)?/
const SCRIPT_URL_REGEX = new RegExp(`^${SCRIPT_URL_PATTERN.source}$`)
const SCRIPT_TAG_REGEX = new RegExp(`src\\s*=\\s*["']?(${SCRIPT_URL_PATTERN.source})(?=["'\\s>]|$)`, 'i')
const SCRIPT_URL_PREFIX_REGEX = /^((?:https?:)?\/\/(?:geo|cdn)\.cookie-script\.com\/)/i

// Like the server: the scheme and host are case-insensitive, the path and banner ID are not
const lowercaseUrlPrefix = (url) => url.replace(SCRIPT_URL_PREFIX_REGEX, (prefix) => prefix.toLowerCase())

/**
 * Accepts either the script URL itself or the full banner code (<script ... src="..."></script>)
 * and returns just the script URL, or null when the input is not a valid CookieScript URL.
 */
export function extractScriptUrl(rawValue) {
  // Pasted HTML may contain "&amp;" between query parameters
  const value = lowercaseUrlPrefix((rawValue || '').trim().replace(/&amp;/gi, '&'))

  if (SCRIPT_URL_REGEX.test(value)) {
    return value
  }

  const scriptTagMatch = value.match(SCRIPT_TAG_REGEX)
  const taggedUrl = scriptTagMatch ? lowercaseUrlPrefix(scriptTagMatch[1]) : null

  return taggedUrl && SCRIPT_URL_REGEX.test(taggedUrl) ? taggedUrl : null
}

export default function ScriptForm({ handleSubmit, isLoading, submitError }) {
  const validationSchema = Yup.object().shape({
    script: Yup.string()
      .required('This field is required')
      .trim()
      .test('cookie-script-url', 'Format is incorrect', (scriptInput) => extractScriptUrl(scriptInput) !== null)
  })

  const initialValues = {
    script: "",
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
          onSubmit={async (values, { resetForm }) => {
            const isAdded = await handleSubmit({ script: extractScriptUrl(values.script) })

            if (isAdded) {
              resetForm(initialValues)
            }
          }}
        >
          {({ errors }) => (
            <Form className="form-control__form">
              <Field className={`form-control__input ${errors.script || submitError ? 'form-control__input-error' : ''}`} id="script"
                name="script" placeholder="//cdn.cookie-script.com/s/********************************.js" />
              {errors.script
                ? <div className="form-control__error">
                  <img src={ErrorIcon} alt="Error Icon" />
                  <ErrorMessage name="script" component="span" />
                </div>
                : null}
              {!errors.script && submitError
                ? <div className="form-control__error">
                  <img src={ErrorIcon} alt="Error Icon" />
                  <span>{submitError}</span>
                </div>
                : null}
              <div className="cookie-script__buttons-wrapper">
                <button className="btn btn--primary" type="submit" disabled={isLoading}>Add Script</button>
              </div>
            </Form>
          )}
        </Formik>
      </div>
      <hr></hr>
    </>
  )
}
