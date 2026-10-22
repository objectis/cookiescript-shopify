import React, { useEffect, useState } from "react"
import AddedScripts from "../AddedScripts"
import ScriptForm from "../ScriptForm"
import CookieScriptInformation from "../CookieScriptInformation"
import DomainBannerMap from "../DomainBannerMap"
import Cookie from "../../assets/images/icons/cookie.svg"
import ErrorIcon from "../../assets/images/icons/erorr-circle.svg"
import { useAuthenticatedFetch } from "../../hooks"
import GoogleConsentMode from "../GoogleConsentMode/index.js"

const DEFAULT_ERROR_MESSAGE = 'Something went wrong. Please try again.'

async function getErrorMessage(response) {
  try {
    const data = await response.json()
    const firstDetail = data?.details && Object.values(data.details).flat()[0]

    if (typeof firstDetail === 'string') {
      return firstDetail
    }

    if (typeof data?.error === 'string') {
      return data.error
    }
  } catch (parseError) {
    // The response has no JSON body, fall back to the generic message
  }

  return DEFAULT_ERROR_MESSAGE
}

export function Application() {
  const fetch = useAuthenticatedFetch()
  const [isLoading, setIsLoading] = useState(false)
  const [scripts, setScripts] = useState([])
  const [addError, setAddError] = useState(null)
  const [scriptsError, setScriptsError] = useState(null)

  async function loadScripts() {
    setIsLoading(true)

    try {
      const response = await fetch('/api/get-script-tags')

      if (!response.ok) {
        setScriptsError(await getErrorMessage(response))
        return
      }

      const data = await response.json()
      setScripts(Array.isArray(data) ? data : [])
    } catch (loadError) {
      console.error('Failed to load scripts', loadError)
      setScriptsError(DEFAULT_ERROR_MESSAGE)
    } finally {
      setIsLoading(false)
    }
  }

  const handleScriptRemove = async (scriptId) => {
    setIsLoading(true)
    setScriptsError(null)

    try {
      const response = await fetch(`/api/remove-script-tag/${scriptId}`, {
        method: 'DELETE'
      })

      if (!response.ok) {
        setScriptsError(await getErrorMessage(response))
      }
    } catch (removeError) {
      console.error('Failed to remove script', removeError)
      setScriptsError(DEFAULT_ERROR_MESSAGE)
    }

    await loadScripts()
  }

  const handleSubmit = async (values) => {
    setIsLoading(true)
    setAddError(null)
    setScriptsError(null)
    let isAdded = false

    try {
      const response = await fetch('/api/add-script', {
        method: 'POST',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ url: values.script })
      })

      isAdded = response.ok

      if (!response.ok) {
        setAddError(await getErrorMessage(response))
      }
    } catch (addScriptError) {
      console.error('Failed to add script', addScriptError)
      setAddError(DEFAULT_ERROR_MESSAGE)
    }

    await loadScripts()

    return isAdded
  }

  useEffect(() => {
    loadScripts()
  }, [])

  return (
    <>
      <h2 className="header">Cookie Script Settings</h2>
      <div className="cookie-script-settings">
        <div className="cookie-script form-wrapper">
          <section>
            {scripts.length !== 0
              ? <header className="cookie-script__header">
                <h2>Already added scripts</h2>
              </header>
              : null
            }
          </section>
          <section>
            <AddedScripts
              scripts={scripts}
              isLoading={isLoading}
              handleScriptRemove={handleScriptRemove}
            />
            {scriptsError
              ? <div className="form-control__error">
                <img src={ErrorIcon} alt="Error Icon" />
                <span>{scriptsError}</span>
              </div>
              : null
            }
            <ScriptForm
              isLoading={isLoading}
              handleSubmit={handleSubmit}
              submitError={addError}
            />
          </section>
          <section>
            <DomainBannerMap />
          </section>
          <section>
            <CookieScriptInformation />
          </section>
          {isLoading
            ? <div className="cookie-script__loading-cover ">
              <img className="rotating" src={Cookie} alt="Cookie" />
            </div>
            : null
          }
        </div>
        <div className="google-consent-mode form-wrapper">
          <GoogleConsentMode />
        </div>
      </div>
    </>
  )
}
