import m from 'mithril'
import { VisualizeContext, VisualizeContextState } from './visualizecontext'

const files = ["RoleConventionsSniff", 'CheckDCIRules', 'ContextVisualization', 'ListContextInformation']

const state : VisualizeContextState = {
    onlyInteractions: false,
    file: files[0]
}

let visualizer : VisualizeContext | null = null;

const createVisualizer = async (vnode : m.VnodeDOM) => {
    const json = await fetch(state.file + '.json')
        .then(response => response.json())

    visualizer = new VisualizeContext(
        json.nodes, 
        json.edges, 
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
            m('span', "Display interactions only"),
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
