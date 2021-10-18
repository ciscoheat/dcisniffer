import m from 'mithril'
import { VisualizeContext, VisualizeContextState } from './visualizecontext'
import { Context } from './context'
import { ContextToVis } from './contexttovis'

const files = ["RoleConventionsSniff", 'CheckDCIRules', 'ListContextInformation']

const state : VisualizeContextState = {
    onlyInteractions: false,
    file: files[0]
}

let visualizer : VisualizeContext | null = null;

const createVisualizer = async (vnode : m.VnodeDOM) => {
    const context : Context = await fetch(state.file + '.json')
        .then(response => response.json())

    let {nodes, edges} = new ContextToVis(context).create()

    visualizer = new VisualizeContext(
        nodes, edges,
        vnode.dom as HTMLElement
    )

    visualizer.start()
}

const update = () => {
    if(visualizer) visualizer.setState(state)
}

const App = {
    view: () => m('#app', {
        onupdate: () => update()
    },
        m('#toolbar', {key: 'toolbar'}, [
            m('input[type=checkbox]', {
                checked: state.onlyInteractions,
                onclick: e => state.onlyInteractions = e.target.checked
            }),
            m('span', "Interactions only"),
            m('.separator'),
            m('span', 'File:'),
            m('select#file', {
                name: 'file',
                onchange: e => state.file = e.target.value
            }, files.map(f => m('option', 
                {value: f, selected: f == state.file},
                f
            )))
        ]),
        m('#mynetwork', {
            key: state.file,
            oncreate: createVisualizer
        })
    )
}

m.mount(document.body, App)
