import React from "react"
import Minus from "../../assets/images/icons/minus.svg"

export default function AddedScripts({scripts, handleScriptRemove}) {
  return (
    <>
      {scripts && scripts.map((script, i) => {
        return (
          <div key={i} className="cookie-script__added-scripts">
            <div key={script.id} className="cookie-script__added-script">
              <div>{script.src}</div>
            </div>
            <div className="cookie-script__added-scripts--remove-script">
              <button className="btn--remove" onClick={() => handleScriptRemove(script.id)}>
                <img src={Minus} alt="Minus"/>
              </button>
            </div>
          </div>
        )
      })}
    </>
  )
}
