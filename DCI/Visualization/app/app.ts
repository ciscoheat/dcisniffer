import m from 'mithril'
import { VisualizeContext, VisualizeContextState } from './visualizecontext'

const state : VisualizeContextState = {
    onlyInteractions: false
}

let visualizer : VisualizeContext | null = null;

const update = () => {
    if(visualizer) visualizer.setState(state)
}

const App = {
    view: () => m('#app', {
        onupdate: () => update()
    }, [
        m('#toolbar', [
            m('input[type=checkbox]', {
                checked: state.onlyInteractions,
                onclick: e => state.onlyInteractions = e.target.checked
            }),
            "Display interactions only"
        ]),
        m('#mynetwork', {
            oncreate: async (vnode : m.VnodeDOM) => {
                const json = await fetch("RoleConventionsSniff.json")
                    .then(response => response.json())

                visualizer = new VisualizeContext(
                    json.nodes, 
                    json.edges, 
                    vnode.dom as HTMLElement
                )
    
                visualizer.start()
            }
        })
    ])
}

m.mount(document.body, App)
