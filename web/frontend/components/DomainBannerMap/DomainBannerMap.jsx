import React, { useEffect, useState } from 'react'
import { Formik, Form, FieldArray } from 'formik'
import * as Yup from 'yup'
import { useAuthenticatedFetch } from '../../hooks'
import ErrorIcon from '../../assets/images/icons/erorr-circle.svg'
import Cookie from '../../assets/images/icons/cookie.svg'
import Minus from '../../assets/images/icons/minus.svg'

function normaliseDomain(rawDomain) {
  let domainValue = (rawDomain || '').trim().toLowerCase()
  if (domainValue.includes('://')) {
    try {
      domainValue = new URL(domainValue).hostname
    } catch (urlParseError) {
      domainValue = domainValue.replace(/^.*?:\/\//, '')
    }
  }
  return domainValue.replace(/\/.*$/, '').replace(/\/$/, '')
}

const SCRIPT_URL_REGEX = /^(https:)?\/\/(geo|cdn)\.cookie-script\.com\/(s|staging)\/[a-zA-Z0-9]{32}\.js$/

const rowSchema = Yup.object({
  domain: Yup.string()
    .required('Domain is required')
    .test('valid-domain', 'Enter a valid hostname (e.g. example.com)', (domainInput) => {
      if (!domainInput) return false
      const normalisedDomain = normaliseDomain(domainInput)
      return normalisedDomain.length > 0 && normalisedDomain.includes('.')
    }),
  url: Yup.string()
    .required('Installation URL is required')
    .test('valid-script-url', 'Must be a CookieScript HTTPS script URL', (scriptUrlInput) => {
      if (!scriptUrlInput) return false
      return SCRIPT_URL_REGEX.test(scriptUrlInput.trim())
    }),
})

const validationSchema = Yup.object({
  domain_map: Yup.array()
    .of(rowSchema)
    .test('no-duplicate-domains', 'Duplicate domains detected', (domainRows) => {
      if (!domainRows) return true
      const seenDomains = new Set()
      for (const row of domainRows) {
        const normalisedDomain = normaliseDomain(row.domain || '')
        if (normalisedDomain && seenDomains.has(normalisedDomain)) return false
        seenDomains.add(normalisedDomain)
      }
      return true
    }),
})

const createEmptyDomainRow = () => ({ domain: '', url: '' })

export default function DomainBannerMap() {
  const fetchWithAuth = useAuthenticatedFetch()
  const [isLoading, setIsLoading] = useState(false)
  const [saveStatus, setSaveStatus] = useState(null)
  const [initialValues, setInitialValues] = useState({
    multi_domain_enabled: false,
    domain_map: [],
  })
  const [isFadingOut, setIsFadingOut] = useState(false)

  useEffect(() => {
    const loadDomainSettings = async () => {
      try {
        const response = await fetchWithAuth('/api/domain-banner-map')
        if (response.ok) {
          const responseData = await response.json()
          const domainRows = Object.entries(responseData.domain_map || {}).map(([domainName, scriptUrl]) => ({
            domain: domainName,
            url: scriptUrl,
          }))
          setInitialValues({
            multi_domain_enabled: Boolean(responseData.multi_domain_enabled),
            domain_map: domainRows,
          })
        }
      } catch (loadError) {
        console.error('DomainBannerMap: failed to load settings', loadError)
      }
    }
    loadDomainSettings()
  }, [])

  const handleSubmit = async (formValues, { setSubmitting }) => {
    setIsLoading(true)
    setSaveStatus(null)
    setTimeout(() => {
      setIsFadingOut(true)
    }, 5000)

    try {
      const formattedDomainMap = formValues.domain_map.map((domainRow) => ({
        domain: normaliseDomain(domainRow.domain),
        url: domainRow.url.trim(),
      }))
      const response = await fetchWithAuth('/api/domain-banner-map', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          multi_domain_enabled: formValues.multi_domain_enabled,
          domain_map: formattedDomainMap,
        }),
      })
      setSaveStatus(response.ok ? 'ok' : 'error')
      if (!response.ok) {
        const errorResponse = await response.json()
        console.error('Save failed:', errorResponse)
      }
    } catch (saveError) {
      console.error('DomainBannerMap: save error', saveError)
      setSaveStatus('error')
    } finally {
      setIsLoading(false)
      setSubmitting(false)

      setTimeout(() => {
        setSaveStatus(null)
        setIsFadingOut(false)
      }, 3500)
    }
  }

  return (
    <div className="domain-banner-map">
      {isLoading && (
        <div className="cookie-script__loading-cover">
          <img className="rotating" src={Cookie} alt="Cookie" />
        </div>
      )}
      <Formik
        initialValues={initialValues}
        validationSchema={validationSchema}
        enableReinitialize
        onSubmit={handleSubmit}
      >
        {({ values, errors, isSubmitting, setFieldValue }) => (
          <Form>
            <div className="domain-banner-map__controls">
              <h2>Multi-Domain Banner</h2>
              <div className="domain-banner-map__toggle-row">
                <label className="domain-banner-map__toggle-label">
                  <input
                    type="checkbox"
                    checked={values.multi_domain_enabled}
                    onChange={(changeEvent) => setFieldValue('multi_domain_enabled', changeEvent.target.checked)}
                  />
                  <span>Use multiple domains</span>
                </label>
                <button
                  className={`btn btn--${isLoading ? 'disabled' : 'primary'}`}
                  type="submit"
                  disabled={isSubmitting || isLoading}
                >
                  Save Settings
                </button>
              </div>
              {saveStatus === 'ok' && (
                <p className={`domain-banner-map__status domain-banner-map__status--ok ${isFadingOut ? 'fade-out' : ''}`}>
                  Settings saved successfully.
                </p>
              )}
            </div>

            {values.multi_domain_enabled && (
              <>
                <FieldArray name="domain_map">
                  {({ push, remove }) => (
                    <div className="domain-banner-map__list">
                      {values.domain_map.length > 0 && (
                        <div className="domain-banner-map__row domain-banner-map__row--header">
                          <span>Shop domain</span>
                          <span>Banner installation URL</span>
                          <span />
                        </div>
                      )}
                      {values.domain_map.map((domainRow, rowIndex) => (
                        <div key={rowIndex} className="domain-banner-map__row">
                          <div className="domain-banner-map__cell">
                            <input
                              className={`form-control__input domain-banner-map__input${errors.domain_map?.[rowIndex]?.domain ? ' form-control__input-error' : ''}`}
                              value={domainRow.domain}
                              placeholder="example.com"
                              onChange={(inputEvent) => setFieldValue(`domain_map[${rowIndex}].domain`, inputEvent.target.value)}
                            />
                            {errors.domain_map?.[rowIndex]?.domain && (
                              <div className="form-control__error">
                                <img src={ErrorIcon} alt="Error" />
                                <span>{errors.domain_map[rowIndex].domain}</span>
                              </div>
                            )}
                          </div>
                          <div className="domain-banner-map__cell">
                            <input
                              className={`form-control__input domain-banner-map__input${errors.domain_map?.[rowIndex]?.url ? ' form-control__input-error' : ''}`}
                              value={domainRow.url}
                              placeholder="//cdn.cookie-script.com/s/********************************.js"
                              onChange={(inputEvent) => setFieldValue(`domain_map[${rowIndex}].url`, inputEvent.target.value)}
                            />
                            {errors.domain_map?.[rowIndex]?.url && (
                              <div className="form-control__error">
                                <img src={ErrorIcon} alt="Error" />
                                <span>{errors.domain_map[rowIndex].url}</span>
                              </div>
                            )}
                          </div>
                          <div className="domain-banner-map__cell domain-banner-map__cell--action">
                            <button
                              type="button"
                              className="btn--remove"
                              onClick={() => remove(rowIndex)}
                              title="Remove domain"
                            >
                              <img src={Minus} alt="Remove" />
                            </button>
                          </div>
                        </div>
                      ))}
                      {typeof errors.domain_map === 'string' && (
                        <div className="form-control__error">
                          <img src={ErrorIcon} alt="Error" />
                          <span>{errors.domain_map}</span>
                        </div>
                      )}
                      <button
                        type="button"
                        className="btn btn--secondary domain-banner-map__add"
                        onClick={() => push(createEmptyDomainRow())}
                      >
                        + Add domain
                      </button>
                    </div>
                  )}
                </FieldArray>
                <p className="domain-banner-map__info">
                  Choose a banner for specific website domains. If a visitor comes from a domain not listed above, they’ll see the default banner.
                </p>
              </>
            )}
          </Form>
        )}
      </Formik>
    </div>
  )
}

